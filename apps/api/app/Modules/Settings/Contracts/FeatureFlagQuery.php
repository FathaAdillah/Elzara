<?php

declare(strict_types=1);

namespace App\Modules\Settings\Contracts;

interface FeatureFlagQuery
{
    public function enabled(string $code, int|string|null $subjectId = null): bool;
}
