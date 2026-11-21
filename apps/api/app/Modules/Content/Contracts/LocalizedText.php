<?php

declare(strict_types=1);

namespace App\Modules\Content\Contracts;

interface LocalizedText
{
    public function value(string $key, string $group, ?string $locale = null, ?int $plural = null): string;
}
