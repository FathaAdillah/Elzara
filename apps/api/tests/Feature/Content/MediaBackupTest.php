<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Modules\Content\Infrastructure\Models\Media;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MediaBackupTest extends TestCase
{
    use DatabaseTransactions;

    private S3Client $root;

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
            'filesystems.disks.product-media.key' => 'elzara-app',
            'filesystems.disks.product-media.secret' => 'elzara-test-secret',
        ]);
        $this->root = $this->client('minioadmin', 'minioadmin');
    }

    public function test_relink_points_media_at_the_restored_version_only_when_the_checksum_matches(): void
    {
        $bytes = '%PDF-1.7 restored '.Str::random(8);
        $restored = $this->media('product-media', $bytes, 'pre-restore-version');
        $unchanged = $this->media('request-attachments', $bytes);
        $tampered = $this->media('product-media', $bytes, 'pre-restore-version', 'tampered bytes');
        $missing = Media::factory()->create(['disk' => 'invoice-documents', 'path' => 'published/'.Str::uuid().'.pdf', 'object_version' => 'gone']);
        $current = $this->root->headObject(['Bucket' => 'product-media', 'Key' => $restored->path])['VersionId'];

        $this->artisan('content:media:relink-versions --dry-run')
            ->expectsOutput('checked=4 unchanged=1 relinked=1 missing=1 mismatched=1 failed=0')->assertExitCode(1);
        $this->assertSame('pre-restore-version', $restored->fresh()?->object_version);

        $this->artisan('content:media:relink-versions')
            ->expectsOutput('checked=4 unchanged=1 relinked=1 missing=1 mismatched=1 failed=0')->assertExitCode(1);
        $this->assertSame($current, $restored->fresh()?->object_version);
        $this->assertSame($unchanged->object_version, $unchanged->fresh()?->object_version);
        $this->assertSame('pre-restore-version', $tampered->fresh()?->object_version);
        $this->assertSame('gone', $missing->fresh()?->object_version);

        $missing->delete();
        $tampered->delete();
        $this->artisan('content:media:relink-versions')
            ->expectsOutput('checked=2 unchanged=2 relinked=0 missing=0 mismatched=0 failed=0')->assertExitCode(0);
    }

    public function test_backup_reader_can_only_read_the_backed_up_buckets(): void
    {
        $reader = $this->client('elzara-backup-reader', 'elzara-backup-test-secret');
        foreach (['product-media', 'request-attachments', 'invoice-documents', 'shipment-documents', 'audit-archives'] as $bucket) {
            $key = 'published/'.Str::uuid().'.pdf';
            $this->root->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => '%PDF-1.7 backup']);
            $this->assertContains($key, array_column($reader->listObjectsV2(['Bucket' => $bucket, 'Prefix' => $key])['Contents'] ?? [], 'Key'));
            $this->assertSame('%PDF-1.7 backup', (string) $reader->getObject(['Bucket' => $bucket, 'Key' => $key])['Body']);
            $this->assertDenied(fn () => $reader->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'overwrite']));
            $this->assertDenied(fn () => $reader->deleteObject(['Bucket' => $bucket, 'Key' => $key]));
        }
        $this->root->putObject(['Bucket' => 'exports', 'Key' => 'export.csv', 'Body' => 'a,b']);
        $this->assertDenied(fn () => $reader->getObject(['Bucket' => 'exports', 'Key' => 'export.csv']));
        $visible = array_column($reader->listBuckets()['Buckets'] ?? [], 'Name');
        sort($visible);
        $this->assertSame(['audit-archives', 'invoice-documents', 'product-media', 'request-attachments', 'shipment-documents'], $visible);
    }

    private function media(string $bucket, string $bytes, ?string $recordedVersion = null, ?string $storedBytes = null): Media
    {
        $key = 'published/'.Str::uuid().'.pdf';
        $version = $this->root->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => $storedBytes ?? $bytes])['VersionId'];

        return Media::factory()->create([
            'disk' => $bucket, 'path' => $key, 'mime' => 'application/pdf', 'kind' => 'file', 'width' => null, 'height' => null,
            'sha256' => hash('sha256', $bytes), 'object_version' => $recordedVersion ?? $version,
        ]);
    }

    private function assertDenied(callable $call): void
    {
        try {
            $call();
            $this->fail('Backup reader must not be allowed this call.');
        } catch (AwsException $e) {
            $this->assertSame('AccessDenied', $e->getAwsErrorCode());
        }
    }

    private function client(string $key, string $secret): S3Client
    {
        return new S3Client([
            'version' => 'latest', 'region' => 'elzara-1', 'endpoint' => getenv('MINIO_TEST_ENDPOINT'), 'use_path_style_endpoint' => true,
            'credentials' => ['key' => $key, 'secret' => $secret],
        ]);
    }
}
