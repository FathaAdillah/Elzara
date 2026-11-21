<?php

declare(strict_types=1);

namespace App\Modules\Content\Application\Commands;

use App\Modules\Content\Application\MediaService;

final class FinalizeMediaUpload
{
    public function __construct(private readonly int $actor, private readonly int $id) {}

    /** @return array{media_id: int, sha256: string|null} */
    public function handle(MediaService $media): array
    {
        return $media->finalize($this->actor, $this->id);
    }
}
