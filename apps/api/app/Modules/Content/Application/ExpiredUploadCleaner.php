<?php

declare(strict_types=1);

namespace App\Modules\Content\Application;

use App\Modules\Content\Infrastructure\Models\Media;
use App\Modules\Content\Infrastructure\Storage\BrowserPresigner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ExpiredUploadCleaner
{
    public function __construct(private readonly BrowserPresigner $presigner) {}

    /** @return array{deleted: int, failed: int} */
    public function run(): array
    {
        $deleted = 0;
        $failed = 0;
        foreach ($this->expired()->lazyById(100) as $candidate) {
            try {
                $deleted += DB::transaction(function () use ($candidate): int {
                    $media = $this->expired()->whereKey($candidate->id)->lockForUpdate()->first();
                    if ($media === null) {
                        return 0;
                    }
                    $this->deleteStagingVersions($media);
                    $media->delete();

                    return 1;
                });
            } catch (RuntimeException) {
                $failed++;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    private function deleteStagingVersions(Media $media): void
    {
        $client = $this->presigner->client();
        do {
            $page = $client->listObjectVersions(['Bucket' => $media->disk, 'Prefix' => $media->path]);
            $deleted = 0;
            foreach (['Versions', 'DeleteMarkers'] as $field) {
                $versions = $page[$field];
                if (! is_array($versions)) {
                    continue;
                }
                foreach ($versions as $version) {
                    if (! is_array($version) || ($version['Key'] ?? null) !== $media->path || ! is_string($version['VersionId'] ?? null)) {
                        continue;
                    }
                    $client->deleteObject(['Bucket' => $media->disk, 'Key' => $media->path, 'VersionId' => $version['VersionId']]);
                    $deleted++;
                }
            }
        } while ($page['IsTruncated'] === true && $deleted > 0);
    }

    /** @return Builder<Media> */
    private function expired(): Builder
    {
        return Media::query()->where('model_type', 'admin-upload')
            ->whereNull('sha256')->where('created_at', '<', now()->subMinutes(30))
            ->where('path', 'like', 'incoming/%')->whereIn('disk', Config::array('media.upload_buckets'));
    }
}
