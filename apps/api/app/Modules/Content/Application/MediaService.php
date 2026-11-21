<?php

declare(strict_types=1);

namespace App\Modules\Content\Application;

use App\Modules\Content\Infrastructure\Models\Media;
use App\Modules\Content\Infrastructure\Storage\BrowserPresigner;
use App\Platform\Domain\Exception\MediaValidationFailed;
use App\Platform\Outbox\DomainEvent;
use App\Platform\Outbox\OutboxWriter;
use Aws\Exception\AwsException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final class MediaService
{
    public function __construct(private readonly BrowserPresigner $presigner, private readonly MagicByteValidator $validator, private readonly OutboxWriter $outbox) {}

    /** @return array{upload_url: string, object_key: string, media_id: int, expires_at: string|null} */
    public function presign(int $actor, string $bucket, string $mime, string $kind, int $size): array
    {
        if (! in_array($bucket, Config::array('media.upload_buckets'), true) || ! in_array($mime, Config::array('media.allowed_mime'), true) || $size < 1 || $size > Config::integer('media.max_bytes')) {
            throw new MediaValidationFailed('media.validation_failed');
        }
        $uuid = (string) Str::uuid();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => 'pdf',
        };
        $key = 'incoming/'.$uuid.'.'.$extension;
        $url = $this->presigner->put($bucket, $key, $mime);
        $media = Media::query()->create([
            'uuid' => $uuid, 'model_type' => 'admin-upload', 'model_id' => $actor,
            'kind' => $kind, 'disk' => $bucket, 'path' => $key, 'mime' => $mime,
        ]);

        return ['upload_url' => $url, 'object_key' => $key, 'media_id' => $media->id, 'expires_at' => now()->addSeconds(600)->toISOString()];
    }

    /** @return array{media_id: int, sha256: string|null} */
    public function finalize(int $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id): array {
            $media = $this->owned($actor, $id)->lockForUpdate()->firstOrFail();
            if ($media->sha256 !== null) {
                return ['media_id' => $media->id, 'sha256' => $media->sha256];
            }
            if ($media->created_at->addMinutes(30)->isPast()) {
                throw new MediaValidationFailed('media.upload_expired');
            }
            try {
                $object = $this->presigner->client()->getObject([
                    'Bucket' => $media->disk, 'Key' => $media->path,
                    'Range' => 'bytes=0-'.Config::integer('media.max_bytes'),
                ]);
            } catch (AwsException $exception) {
                if (in_array($exception->getStatusCode(), [404, 416], true) || $exception->getAwsErrorCode() === 'NoSuchKey') {
                    throw new MediaValidationFailed('media.object_missing');
                }
                throw new RuntimeException('Object storage unavailable.');
            }
            $body = $object['Body'];
            if (! $body instanceof StreamInterface) {
                throw new RuntimeException('Object storage returned no stream.');
            }
            $bytes = (string) $body;
            $version = $object['VersionId'];
            if (! is_string($version) || $version === '' || $version === 'null') {
                throw new RuntimeException('Object storage versioning is required.');
            }
            $this->validator->validate($bytes, $media->mime);
            $stagingKey = $media->path;
            $publishedKey = 'published/'.basename($stagingKey);
            try {
                $copy = $this->presigner->client()->copyObject([
                    'Bucket' => $media->disk, 'Key' => $publishedKey,
                    'CopySource' => $media->disk.'/'.str_replace('%2F', '/', rawurlencode($stagingKey)).'?versionId='.rawurlencode($version),
                    'MetadataDirective' => 'REPLACE', 'ContentType' => $media->mime,
                ]);
            } catch (AwsException) {
                throw new RuntimeException('Object storage unavailable.');
            }
            $publishedVersion = $copy['VersionId'];
            if (! is_string($publishedVersion) || $publishedVersion === '' || $publishedVersion === 'null') {
                throw new RuntimeException('Object storage versioning is required.');
            }
            $bucket = $media->disk;
            try {
                $dimensions = str_starts_with($media->mime, 'image/') ? @getimagesizefromstring($bytes) : false;
                $media->forceFill([
                    'path' => $publishedKey,
                    'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'object_version' => $publishedVersion,
                    'width' => $dimensions === false ? null : $dimensions[0], 'height' => $dimensions === false ? null : $dimensions[1],
                    'is_moderated' => in_array($media->kind, ['proof', 'attachment'], true), 'moderation_status' => 'pending',
                ])->save();
                $this->outbox->record(new DomainEvent('MediaValidated', 'media', $media->id, [
                    'media_id' => $media->id, 'model_type' => $media->model_type, 'model_id' => $media->model_id,
                    'kind' => $media->kind, 'outcome' => 'validated', 'reason_code' => null,
                ]));
            } catch (Throwable $exception) {
                try {
                    $this->presigner->client()->deleteObject(['Bucket' => $bucket, 'Key' => $publishedKey, 'VersionId' => $publishedVersion]);
                } catch (AwsException) {
                    report(new RuntimeException('Published copy cleanup failed.'));
                }
                throw $exception;
            }
            DB::afterCommit(function () use ($bucket, $stagingKey, $version): void {
                try {
                    $this->presigner->client()->deleteObject(['Bucket' => $bucket, 'Key' => $stagingKey, 'VersionId' => $version]);
                } catch (AwsException) {
                    report(new RuntimeException('Staging object cleanup failed.'));
                }
            });

            return ['media_id' => $media->id, 'sha256' => $media->sha256];
        });
    }

    /** @return array{download_url: string, expires_at: string|null} */
    public function sign(int $actor, int $id): array
    {
        $media = $this->owned($actor, $id)->firstOrFail();
        if ($media->sha256 === null || $media->object_version === null) {
            throw new MediaValidationFailed('media.not_validated');
        }

        return ['download_url' => $this->presigner->get($media->disk, $media->path, $media->object_version), 'expires_at' => now()->addSeconds(120)->toISOString()];
    }

    /** @return Builder<Media> */
    private function owned(int $actor, int $id): Builder
    {
        return Media::query()->whereKey($id)->where('model_type', 'admin-upload')->where('model_id', $actor);
    }
}
