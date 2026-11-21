<?php

declare(strict_types=1);

namespace Database\Seeders\Reference;

use App\Modules\Settings\Infrastructure\Models\Setting;
use Illuminate\Database\Seeder;

final class SettingSeeder extends Seeder
{
    /** @var list<string> */
    public const KEYS = ['money.rounding', 'po.reconsent_threshold_percent', 'intl_fee.auto_issue_cap', 'wa.provider', 'tax.ems_basis'];

    public function run(): void
    {
        foreach (self::KEYS as $key) {
            Setting::query()->firstOrCreate(['key' => $key], [
                'group_name' => explode('.', $key)[0],
                'value_type' => 'string',
                'value' => ['v' => ''],
                'default_value' => ['v' => ''],
                'is_public' => false,
            ]);
        }
    }
}
