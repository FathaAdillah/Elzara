<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application;

use App\Modules\Settings\Contracts\SettingValueQuery;
use App\Modules\Settings\Infrastructure\Models\Setting;

final class DatabaseSettingValueQuery implements SettingValueQuery
{
    public function value(string $key): mixed
    {
        $setting = Setting::query()->where('key', $key)->firstOrFail();

        return array_key_exists('v', $setting->value) ? $setting->value['v'] : ($setting->default_value['v'] ?? null);
    }
}
