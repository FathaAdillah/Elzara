<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Settings\Infrastructure\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Setting> */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'test.'.Str::lower(Str::random(12)),
            'group_name' => 'test',
            'value_type' => 'string',
            'value' => ['v' => 'value'],
            'default_value' => ['v' => ''],
            'is_public' => false,
        ];
    }
}
