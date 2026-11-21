<?php

declare(strict_types=1);

namespace App\Modules\Content\Application;

use App\Modules\Content\Infrastructure\Models\Media;
use App\Modules\Content\Infrastructure\Storage\BrowserPresigner;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Config;
use Psr\Http\Message\StreamInterface;

/**
 * Restore objek dari salinan off-node menulis ulang setiap objek sehingga VersionId berubah, sedangkan
 * `media.object_version` masih menunjuk versi lama. Hanya versi terkini yang sha256-nya sama dengan
 * `media.sha256` yang ditautkan ulang; selain itu dilaporkan dan dibiarkan.
 */
final class MediaVersionRelinker
{
    public function __construct(private readonly BrowserPresigner $presigner) {}

    /** @return array{checked: int, unchanged: int, relinked: int, missing: int, mismatched: int, failed: int} */
    public function run(bool $dryRun = false): array
    {
        $result = ['checked' => 0, 'unchanged' => 0, 'relinked' => 0, 'missing' => 0, 'mismatched' => 0, 'failed' => 0];
        $client = $this->presigner->client();
        $query = Media::query()->where('path', 'like', 'published/%')->whereNotNull('sha256')
            ->whereNotNull('object_version')->whereIn('disk', Config::array('media.upload_buckets'));
        foreach ($query->lazyById(100) as $media) {
            $result['checked']++;
            try {
                $current = $client->headObject(['Bucket' => $media->disk, 'Key' => $media->path])['VersionId'];
                if (! is_string($current) || $current === '' || $current === 'null') {
                    $result['failed']++;

                    continue;
                }
                if ($current === $media->object_version) {
                    $result['unchanged']++;

                    continue;
                }
                $body = $client->getObject(['Bucket' => $media->disk, 'Key' => $media->path, 'VersionId' => $current])['Body'];
                if (! $body instanceof StreamInterface || ! hash_equals((string) $media->sha256, $this->sha256($body))) {
                    $result['mismatched']++;

                    continue;
                }
                if (! $dryRun) {
                    $media->forceFill(['object_version' => $current])->save();
                }
                $result['relinked']++;
            } catch (S3Exception $e) {
                $result[$e->getStatusCode() === 404 ? 'missing' : 'failed']++;
            }
        }

        return $result;
    }

    private function sha256(StreamInterface $body): string
    {
        $context = hash_init('sha256');
        while (! $body->eof()) {
            hash_update($context, $body->read(1 << 20));
        }

        return hash_final($context);
    }
}
