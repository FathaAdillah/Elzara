<?php

declare(strict_types=1);

namespace App\Modules\Content\Application\Commands;

use App\Modules\Content\Application\MediaService;

final class PresignMediaUpload
{
    public function __construct(private readonly int $actor, private readonly string $bucket, private readonly string $mime, private readonly string $kind, private readonly int $size) {}

    /** @return array{upload_url: string, object_key: string, media_id: int, expires_at: string|null} */
    public function handle(MediaService $media): array
    {
        return $media->presign($this->actor, $this->bucket, $this->mime, $this->kind, $this->size);
    }
}
