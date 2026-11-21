<?php

declare(strict_types=1);

namespace App\Modules\Content\Application;

use App\Platform\Domain\Exception\MediaValidationFailed;
use Illuminate\Support\Facades\Config;

final class MagicByteValidator
{
    public function validate(string $bytes, string $claimed): void
    {
        $detected = match (true) {
            str_starts_with($bytes, "\xff\xd8\xff") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
        if (! in_array($claimed, Config::array('media.allowed_mime'), true) || $detected !== $claimed || strlen($bytes) > Config::integer('media.max_bytes')) {
            throw new MediaValidationFailed('media.validation_failed');
        }
    }
}
