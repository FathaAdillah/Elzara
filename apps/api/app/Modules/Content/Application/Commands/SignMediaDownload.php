<?php

declare(strict_types=1);

namespace App\Modules\Content\Application\Commands;

use App\Modules\Content\Application\MediaService;

final class SignMediaDownload
{
    public function __construct(private readonly int $actor, private readonly int $id) {}

    /** @return array{download_url: string, expires_at: string} */
    public function handle(MediaService $media): array
    {
        return $media->sign($this->actor, $this->id);
    }
}
