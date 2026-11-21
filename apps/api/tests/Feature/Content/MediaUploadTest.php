<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Modules\Content\Application\MediaService;
use App\Modules\Content\Infrastructure\Models\Media;
use App\Modules\Content\Infrastructure\Storage\BrowserPresigner;
use App\Platform\Outbox\OutboxWriter;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MediaUploadTest extends TestCase
{
    use DatabaseTransactions;

    private static int $clientSequence = 0;

    private array $cookies = [];

    private string $clientIp;

    protected function setUp(): void
    {
        parent::setUp();
        $endpoint = getenv('MINIO_TEST_ENDPOINT');
        if (! $endpoint) {
            $this->markTestSkipped('Set MINIO_TEST_ENDPOINT to run live MinIO tests.');
        }
        try {
            (new Client(['timeout' => 2]))->get($endpoint.'/minio/health/live');
        } catch (GuzzleException) {
            $this->markTestSkipped('Live MinIO is unavailable.');
        }
        config([
            'filesystems.s3.internal_endpoint' => $endpoint,
            'filesystems.s3.presign_endpoint' => getenv('MINIO_TEST_PUBLIC_ENDPOINT') ?: 'http://storage.localhost:9080',
            'filesystems.disks.product-media.key' => 'elzara-app',
            'filesystems.disks.product-media.secret' => 'elzara-test-secret',
        ]);
        $this->clientIp = '2001:db8::'.dechex(++self::$clientSequence);
        $this->login();
    }

    public function test_real_put_finalize_replay_and_private_versioned_download(): void
    {
        $bytes = '%PDF-1.7 live upload';
        $upload = $this->presign();
        $this->assertSame('storage.localhost', parse_url($upload['upload_url'], PHP_URL_HOST));
        $this->assertMatchesRegularExpression('#^/request-attachments/incoming/[0-9a-f-]{36}\.pdf$#', parse_url($upload['upload_url'], PHP_URL_PATH));
        $this->assertStringContainsString('X-Amz-Expires=600', $upload['upload_url']);
        $this->assertStringNotContainsString('minio:9000', $upload['upload_url']);
        $this->uploadObject($upload, $bytes);
        $path = '/api/v1/admin/media/'.$upload['media_id'];
        $key = (string) Str::uuid();
        $first = $this->request($path.'/finalize', [], $key)->assertOk()->assertJsonPath('sha256', hash('sha256', $bytes));
        $replay = $this->request($path.'/finalize', [], $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->getContent(), $replay->getContent());
        $this->request($path.'/finalize')->assertOk();
        $this->assertSame(1, DB::table('outbox_events')->where('event_name', 'MediaValidated')->where('aggregate_id', $upload['media_id'])->count());
        $this->assertDatabaseHas('media', ['id' => $upload['media_id'], 'size_bytes' => strlen($bytes), 'is_moderated' => true, 'moderation_status' => 'pending']);
        $signed = $this->request($path.'/sign')->assertOk()->json('download_url');
        $this->assertStringContainsString('X-Amz-Expires=120', $signed);
        $this->assertStringContainsString('versionId=', $signed);
        $this->assertSame($bytes, (string) $this->browser()->get($signed)->getBody());
        $this->uploadObject($upload, 'overwritten after validation');
        $this->assertSame($bytes, (string) $this->browser()->get($signed)->getBody());
        $unsigned = strtok($signed, '?');
        $this->assertSame(403, $this->browser()->get($unsigned)->getStatusCode());
    }

    #[DataProvider('invalidObjects')]
    public function test_finalize_rejects_invalid_objects(string $bytes): void
    {
        foreach (['request-attachments', 'product-media'] as $bucket) {
            $upload = $this->request('/api/v1/admin/media/presign', ['bucket' => $bucket] + $this->payload())->assertOk()->json();
            if (strlen($bytes) > config('media.max_bytes')) {
                app(BrowserPresigner::class)->client()->putObject(['Bucket' => $bucket, 'Key' => $upload['object_key'], 'Body' => $bytes]);
            } else {
                $this->uploadObject($upload, $bytes);
            }
            $this->request('/api/v1/admin/media/'.$upload['media_id'].'/finalize')->assertStatus(422)->assertJsonPath('code', 'MEDIA_VALIDATION_FAILED');
            $this->assertNull(Media::query()->findOrFail($upload['media_id'])->sha256);
            $this->assertSame(0, DB::table('outbox_events')->where('event_name', 'MediaValidated')->where('aggregate_id', $upload['media_id'])->count());
            $this->request('/api/v1/admin/media/'.$upload['media_id'].'/sign')->assertStatus(422);
            $this->assertSame(403, $this->browser()->get(strtok($upload['upload_url'], '?'))->getStatusCode());
            if ($bucket === 'product-media') {
                $this->assertSame(404, $this->browser()->get(config('filesystems.s3.presign_endpoint').'/product-media/published/'.basename($upload['object_key']))->getStatusCode());
            }
        }
    }

    public function test_public_media_is_private_until_validated_and_old_put_cannot_replace_published_bytes(): void
    {
        $upload = $this->request('/api/v1/admin/media/presign', ['bucket' => 'product-media'] + $this->payload())->assertOk()->json();
        $this->uploadObject($upload, '%PDF-1.7 approved bytes');
        $stagingUrl = strtok($upload['upload_url'], '?');
        $this->assertSame(403, $this->browser()->get($stagingUrl)->getStatusCode());
        $path = '/api/v1/admin/media/'.$upload['media_id'];
        $this->request($path.'/finalize')->assertOk();
        $media = Media::query()->findOrFail($upload['media_id']);
        $this->assertStringStartsWith('published/', $media->path);
        try {
            app(BrowserPresigner::class)->client()->headObject(['Bucket' => 'product-media', 'Key' => $upload['object_key']]);
            $this->fail('Validated staging version must be deleted after commit.');
        } catch (AwsException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $publicUrl = config('filesystems.s3.presign_endpoint').'/product-media/'.$media->path;
        $this->assertSame('%PDF-1.7 approved bytes', (string) $this->browser()->get($publicUrl)->getBody());
        $signed = $this->request($path.'/sign')->assertOk()->json('download_url');
        $this->uploadObject($upload, '<script>unvalidated replacement</script>');
        $this->assertSame(403, $this->browser()->get($stagingUrl)->getStatusCode());
        $this->assertSame('%PDF-1.7 approved bytes', (string) $this->browser()->get($publicUrl)->getBody());
        $this->assertSame('%PDF-1.7 approved bytes', (string) $this->browser()->get($signed)->getBody());
    }

    public static function invalidObjects(): array
    {
        return ['png claimed as pdf' => ["\x89PNG\r\n\x1a\n"], 'text' => ['text'], 'empty' => [''], 'oversize' => ['%PDF-'.str_repeat('x', 20 * 1024 * 1024)]];
    }

    public function test_presign_rejects_bad_mime_size_bucket_and_empty_input(): void
    {
        foreach ([['mime' => 'text/plain'], ['size_bytes' => 20971521], ['bucket' => 'audit-archives'], ['bucket' => 'exports'], ['bucket' => 'unknown']] as $invalid) {
            $this->request('/api/v1/admin/media/presign', $invalid + $this->payload())->assertStatus(422)->assertJsonPath('code', 'MEDIA_VALIDATION_FAILED');
        }
        $this->request('/api/v1/admin/media/presign')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_png_claimed_as_jpeg_is_rejected_and_actual_png_records_dimensions(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true);
        foreach (['image/jpeg', 'image/png'] as $mime) {
            $upload = $this->request('/api/v1/admin/media/presign', ['mime' => $mime, 'kind' => 'image'] + $this->payload())->assertOk()->json();
            $this->assertSame(200, $this->browser()->put($upload['upload_url'], ['headers' => ['Content-Type' => $mime], 'body' => $png])->getStatusCode());
            $response = $this->request('/api/v1/admin/media/'.$upload['media_id'].'/finalize');
            if ($mime === 'image/jpeg') {
                $response->assertStatus(422)->assertJsonPath('code', 'MEDIA_VALIDATION_FAILED');
                $this->assertNull(Media::query()->findOrFail($upload['media_id'])->sha256);
            } else {
                $response->assertOk();
                $this->assertDatabaseHas('media', ['id' => $upload['media_id'], 'width' => 1, 'height' => 1, 'mime' => 'image/png']);
            }
        }
    }

    public function test_storage_failure_is_safe_and_retryable_without_recording_validation(): void
    {
        $upload = $this->presign();
        $this->uploadObject($upload, '%PDF-1.7 retry');
        $endpoint = config('filesystems.s3.internal_endpoint');
        config(['filesystems.s3.internal_endpoint' => 'http://127.0.0.1:1']);
        $key = (string) Str::uuid();
        $path = '/api/v1/admin/media/'.$upload['media_id'].'/finalize';
        $response = $this->request($path, [], $key)->assertStatus(500)->assertJsonPath('code', 'INTERNAL')->assertJsonPath('retryable', true);
        $this->assertStringNotContainsString('127.0.0.1:1', $response->getContent());
        $this->assertNull(Media::query()->findOrFail($upload['media_id'])->sha256);
        config(['filesystems.s3.internal_endpoint' => $endpoint]);
        $this->request($path, [], $key)->assertOk();
    }

    public function test_missing_unpresigned_and_unowned_objects_cannot_be_finalized_or_signed(): void
    {
        $upload = $this->presign();
        $this->request('/api/v1/admin/media/'.$upload['media_id'].'/finalize')->assertStatus(422)->assertJsonPath('code', 'MEDIA_VALIDATION_FAILED');
        $this->request('/api/v1/admin/media/9223372036854775806/finalize')->assertNotFound();
        $this->login();
        foreach (['finalize', 'sign'] as $action) {
            $this->request('/api/v1/admin/media/'.$upload['media_id'].'/'.$action)->assertNotFound();
        }
    }

    public function test_failed_outbox_persistence_removes_only_the_copied_version_and_preserves_staging_for_retry(): void
    {
        $root = new S3Client([
            'version' => 'latest', 'region' => 'elzara-1', 'endpoint' => getenv('MINIO_TEST_ENDPOINT'), 'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'minioadmin', 'secret' => 'minioadmin'],
        ]);
        foreach ([false, true] as $existingVersion) {
            $upload = $this->request('/api/v1/admin/media/presign', ['bucket' => 'product-media'] + $this->payload())->assertOk()->json();
            $this->uploadObject($upload, '%PDF-1.7 persistence retry');
            $publishedKey = 'published/'.basename($upload['object_key']);
            $baseline = $existingVersion ? $root->putObject(['Bucket' => 'product-media', 'Key' => $publishedKey, 'Body' => '%PDF-1.7 prior version'])['VersionId'] : null;
            app()->instance(OutboxWriter::class, new OutboxWriter('missing-outbox-connection'));
            $path = '/api/v1/admin/media/'.$upload['media_id'].'/finalize';
            $key = (string) Str::uuid();
            try {
                app(MediaService::class)->finalize(Media::query()->findOrFail($upload['media_id'])->model_id, $upload['media_id']);
                $this->fail('Outbox persistence must fail after the copy.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Database connection [missing-outbox-connection] not configured.', $exception->getMessage());
            }
            $this->assertDatabaseHas('media', ['id' => $upload['media_id'], 'path' => $upload['object_key'], 'sha256' => null, 'object_version' => null]);
            $this->assertSame(0, DB::table('outbox_events')->where('event_name', 'MediaValidated')->where('aggregate_id', $upload['media_id'])->count());
            $versions = $root->listObjectVersions(['Bucket' => 'product-media', 'Prefix' => $publishedKey]);
            $this->assertSame($existingVersion ? [$baseline] : [], array_column($versions['Versions'] ?? [], 'VersionId'));
            $this->assertEmpty($versions['DeleteMarkers']);
            $this->assertSame('%PDF-1.7 persistence retry', (string) $root->getObject(['Bucket' => 'product-media', 'Key' => $upload['object_key']])['Body']);
            app()->instance(OutboxWriter::class, new OutboxWriter);
            $this->request($path, [], $key)->assertOk();
            $this->assertSame(1, DB::table('outbox_events')->where('event_name', 'MediaValidated')->where('aggregate_id', $upload['media_id'])->count());
            $versions = $root->listObjectVersions(['Bucket' => 'product-media', 'Prefix' => $publishedKey]);
            $this->assertCount($existingVersion ? 2 : 1, $versions['Versions']);
        }
    }

    public function test_cleanup_deletes_expired_staging_versions_but_preserves_recent_and_finalized_media(): void
    {
        $expired = $this->presign();
        $this->uploadObject($expired, '%PDF-1.7 old version');
        $this->uploadObject($expired, '%PDF-1.7 new version');
        $client = app(BrowserPresigner::class)->client();
        $client->deleteObject(['Bucket' => 'request-attachments', 'Key' => $expired['object_key']]);
        $neighbor = $expired['object_key'].'.neighbor';
        $client->putObject(['Bucket' => 'request-attachments', 'Key' => $neighbor, 'Body' => '%PDF-1.7 neighbor']);
        $missing = $this->presign();
        $recent = $this->presign();
        $this->uploadObject($recent, '%PDF-1.7 recent');
        $finalized = $this->presign();
        $this->uploadObject($finalized, '%PDF-1.7 finalized');
        $this->request('/api/v1/admin/media/'.$finalized['media_id'].'/finalize')->assertOk();
        Media::query()->whereIn('id', [$expired['media_id'], $missing['media_id'], $finalized['media_id']])->update(['created_at' => now()->subMinutes(31)]);

        $this->artisan('content:media:cleanup')->expectsOutput('deleted=2 failed=0')->assertExitCode(0);
        $this->assertDatabaseMissing('media', ['id' => $expired['media_id']]);
        $this->assertDatabaseMissing('media', ['id' => $missing['media_id']]);
        $this->assertDatabaseHas('media', ['id' => $recent['media_id'], 'sha256' => null]);
        $versions = $client->listObjectVersions(['Bucket' => 'request-attachments', 'Prefix' => $expired['object_key']]);
        $this->assertSame([$neighbor], array_column($versions['Versions'] ?? [], 'Key'));
        $this->assertEmpty($versions['DeleteMarkers']);
        $this->assertSame('%PDF-1.7 recent', (string) $client->getObject(['Bucket' => 'request-attachments', 'Key' => $recent['object_key']])['Body']);
        $signed = $this->request('/api/v1/admin/media/'.$finalized['media_id'].'/sign')->assertOk()->json('download_url');
        $this->assertSame('%PDF-1.7 finalized', (string) $this->browser()->get($signed)->getBody());
        $this->artisan('content:media:cleanup')->expectsOutput('deleted=0 failed=0')->assertExitCode(0);
    }

    public function test_cleanup_storage_failure_preserves_the_reservation_for_retry(): void
    {
        $upload = $this->presign();
        $this->uploadObject($upload, '%PDF-1.7 retry cleanup');
        Media::query()->whereKey($upload['media_id'])->update(['created_at' => now()->subMinutes(31)]);
        $endpoint = config('filesystems.s3.internal_endpoint');
        config(['filesystems.s3.internal_endpoint' => 'http://127.0.0.1:1']);
        $this->artisan('content:media:cleanup')->expectsOutput('deleted=0 failed=1')->assertExitCode(1);
        $this->assertDatabaseHas('media', ['id' => $upload['media_id'], 'sha256' => null]);
        config(['filesystems.s3.internal_endpoint' => $endpoint]);
        $this->artisan('content:media:cleanup')->expectsOutput('deleted=1 failed=0')->assertExitCode(0);
        $this->assertDatabaseMissing('media', ['id' => $upload['media_id']]);
    }

    public function test_cleanup_empty_batch_and_hourly_schedule(): void
    {
        $this->artisan('content:media:cleanup')->expectsOutput('deleted=0 failed=0')->assertExitCode(0);
        $events = array_values(array_filter(app(Schedule::class)->events(), fn ($event) => str_contains($event->command ?? '', 'content:media:cleanup')));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    public function test_expired_upload_and_auth_csrf_idempotency_guards(): void
    {
        $upload = $this->presign();
        Media::query()->whereKey($upload['media_id'])->update(['created_at' => now()->subMinutes(31)]);
        $this->request('/api/v1/admin/media/'.$upload['media_id'].'/finalize')->assertStatus(422);
        $this->request('/api/v1/admin/media/presign', $this->payload(), '')->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        $token = $this->cookies['XSRF-TOKEN'];
        unset($this->cookies['XSRF-TOKEN']);
        $this->request('/api/v1/admin/media/presign', $this->payload())->assertStatus(419);
        $this->cookies['XSRF-TOKEN'] = $token;
        DB::table('user_sessions')->update(['revoked_at' => now()]);
        $this->request('/api/v1/admin/media/presign', $this->payload())->assertUnauthorized();
    }

    public function test_bucket_inventory_permissions_cors_and_private_console(): void
    {
        $root = new S3Client([
            'version' => 'latest', 'region' => 'elzara-1', 'endpoint' => getenv('MINIO_TEST_ENDPOINT'), 'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'minioadmin', 'secret' => 'minioadmin'],
        ]);
        $expected = ['audit-archives', 'exports', 'invoice-documents', 'product-media', 'request-attachments', 'shipment-documents'];
        $actual = array_column($root->listBuckets()['Buckets'], 'Name');
        sort($actual);
        $this->assertSame($expected, $actual);
        foreach ($expected as $bucket) {
            $key = 'published/'.Str::uuid().'.pdf';
            $root->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => '%PDF-1.7 inventory']);
            $url = config('filesystems.s3.presign_endpoint').'/'.$bucket.'/'.$key;
            $this->assertSame($bucket === 'product-media' ? 200 : 403, $this->browser()->get($url)->getStatusCode(), $bucket);
            $this->assertSame('Enabled', $root->getBucketVersioning(['Bucket' => $bucket])['Status']);
            if (in_array($bucket, config('media.upload_buckets'), true)) {
                $rules = $root->getBucketLifecycleConfiguration(['Bucket' => $bucket])['Rules'];
                $this->assertContains('incoming/', array_map(fn ($rule) => $rule['Filter']['Prefix'] ?? $rule['Prefix'] ?? null, $rules));
                $this->assertContains(1, array_column(array_column($rules, 'NoncurrentVersionExpiration'), 'NoncurrentDays'));
            }
        }
        $origin = config('filesystems.s3.presign_endpoint');
        $this->assertSame(403, $this->browser()->get($origin.'/product-media?list-type=2')->getStatusCode());
        foreach (['http://localhost', 'http://admin.localhost', 'https://localhost', 'https://admin.localhost'] as $allowed) {
            $response = $this->browser()->request('OPTIONS', $origin.'/request-attachments/fixture.pdf', ['headers' => ['Origin' => $allowed, 'Access-Control-Request-Method' => 'PUT', 'Access-Control-Request-Headers' => 'content-type']]);
            $this->assertSame($allowed, $response->getHeaderLine('Access-Control-Allow-Origin'));
        }
        $response = $this->browser()->request('OPTIONS', $origin.'/request-attachments/fixture.pdf', ['headers' => ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'PUT']]);
        $this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $rules = $root->getBucketLifecycleConfiguration(['Bucket' => 'exports'])['Rules'];
        $this->assertContains(7, array_column(array_column($rules, 'Expiration'), 'Days'));
        $this->assertContains(7, array_column(array_column($rules, 'NoncurrentVersionExpiration'), 'NoncurrentDays'));
        $this->assertContains(true, array_column(array_column($rules, 'Expiration'), 'ExpiredObjectDeleteMarker'));
        $app = app(BrowserPresigner::class)->client();
        foreach ([['Bucket' => 'audit-archives', 'Key' => 'fixture'], ['Bucket' => 'exports', 'Key' => 'fixture']] as $object) {
            try {
                if ($object['Bucket'] === 'audit-archives') {
                    $app->getObject($object);
                } else {
                    $app->deleteObject($object);
                }
                $this->fail('Restricted operation must be denied.');
            } catch (AwsException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $console = curl_init('http://127.0.0.1:9001');
        curl_setopt_array($console, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1, CURLOPT_PROXY => '']);
        curl_exec($console);
        $this->assertSame(7, curl_errno($console), 'MinIO console must not be published.');
    }

    private function presign(): array
    {
        return $this->request('/api/v1/admin/media/presign', $this->payload())->assertOk()->json();
    }

    private function payload(): array
    {
        return ['bucket' => 'request-attachments', 'mime' => 'application/pdf', 'kind' => 'attachment', 'size_bytes' => 24];
    }

    private function uploadObject(array $upload, string $bytes): void
    {
        $this->assertSame(200, $this->browser()->put($upload['upload_url'], ['headers' => ['Content-Type' => 'application/pdf'], 'body' => $bytes])->getStatusCode());
    }

    private function browser(): Client
    {
        $port = parse_url(config('filesystems.s3.presign_endpoint'), PHP_URL_PORT) ?: 80;

        return new Client(['http_errors' => false, 'timeout' => 30, 'proxy' => '', 'curl' => [CURLOPT_RESOLVE => ['storage.localhost:'.$port.':127.0.0.1']]]);
    }

    private function login(): void
    {
        app()->instance('env', 'local');
        $email = strtolower((string) Str::ulid()).'@example.test';
        DB::table('users')->insert([
            'public_id' => (string) Str::ulid(), 'name' => 'Media operator', 'email' => $email,
            'password_hash' => password_hash('fixture-password', PASSWORD_BCRYPT, ['cost' => 4]), 'is_operator' => true, 'status' => 'active',
        ]);
        config(['auth.local_admin_password_only_email' => $email]);
        $this->cookies = [];
        $csrf = $this->request('/sanctum/csrf-cookie?scope=admin&purpose=login', [], '', 'GET')->assertNoContent();
        foreach ($csrf->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
        $response = $this->request('/api/v1/admin/auth/login', ['email' => $email, 'password' => 'fixture-password'])->assertOk();
        foreach ($response->headers->getCookies() as $cookie) {
            if (! $cookie->isCleared()) {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
        $csrf = $this->request('/sanctum/csrf-cookie?scope=admin', [], '', 'GET')->assertNoContent();
        foreach ($csrf->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
    }

    private function request(string $path, array $payload = [], ?string $key = null, string $method = 'POST'): TestResponse
    {
        $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'https://localhost', 'HTTPS' => 'on', 'REMOTE_ADDR' => $this->clientIp];
        if (isset($this->cookies['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }
        if ($key !== '') {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $key ?? (string) Str::uuid();
        }

        return $this->call($method, $path, [], $this->cookies, [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
