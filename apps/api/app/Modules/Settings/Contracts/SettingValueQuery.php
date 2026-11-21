<?php

declare(strict_types=1);

namespace App\Modules\Settings\Contracts;

interface SettingValueQuery
{
    public function value(string $key): mixed;
}
