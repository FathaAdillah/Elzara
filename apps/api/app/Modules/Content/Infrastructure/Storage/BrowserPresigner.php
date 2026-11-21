<?php

declare(strict_types=1);

namespace App\Modules\Content\Infrastructure\Storage;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

final class BrowserPresigner
{
    public function client(bool $browser = false): S3Client
    {
        $endpoint = Config::string($browser ? 'filesystems.s3.presign_endpoint' : 'filesystems.s3.internal_endpoint');
        if ($browser && ! in_array(parse_url($endpoint, PHP_URL_PATH), [null, '', '/'], true)) {
            throw new InvalidArgumentException('MINIO_PUBLIC_ENDPOINT harus origin tanpa prefix path.');
        }

        return new S3Client([
            'version' => 'latest',
            'region' => Config::string('filesystems.disks.product-media.region'),
            'credentials' => [
                'key' => Config::string('filesystems.disks.product-media.key'),
                'secret' => Config::string('filesystems.disks.product-media.secret'),
            ],
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'http' => ['connect_timeout' => 2, 'timeout' => 15],
        ]);
    }

    public function put(string $bucket, string $key, string $mime): string
    {
        $client = $this->client(true);

        return (string) $client->createPresignedRequest($client->getCommand('PutObject', [
            'Bucket' => $bucket, 'Key' => $key, 'ContentType' => $mime,
        ]), '+600 seconds')->getUri();
    }

    public function get(string $bucket, string $key, string $version): string
    {
        $client = $this->client(true);

        return (string) $client->createPresignedRequest($client->getCommand('GetObject', [
            'Bucket' => $bucket, 'Key' => $key, 'VersionId' => $version,
        ]), '+120 seconds')->getUri();
    }
}
