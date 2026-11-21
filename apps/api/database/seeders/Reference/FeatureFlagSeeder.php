<?php

declare(strict_types=1);

namespace Database\Seeders\Reference;

use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use Illuminate\Database\Seeder;

final class FeatureFlagSeeder extends Seeder
{
    /** @var array<string, array{description: string, default_enabled: bool}> */
    public const FLAGS = [
        'checkout.enabled' => ['description' => 'Kill switch checkout (runbook emergency-disable-checkout)', 'default_enabled' => true],
        'marketplace_purchase.enabled' => ['description' => 'Pembelian jalur marketplace', 'default_enabled' => false],
        'reviews.enabled' => ['description' => 'Ulasan produk', 'default_enabled' => false],
        'loyalty.enabled' => ['description' => 'Program poin loyalitas', 'default_enabled' => false],
        'guest_checkout.enabled' => ['description' => 'Checkout tanpa akun', 'default_enabled' => false],
        'drops.enabled' => ['description' => 'Rilis drop terjadwal', 'default_enabled' => false],
        'auth.social_google' => ['description' => 'Login sosial Google', 'default_enabled' => false],
    ];

    public function run(): void
    {
        foreach (self::FLAGS as $code => $flag) {
            FeatureFlag::query()->firstOrCreate(['code' => $code], $flag + ['requires_restart' => false]);
        }
    }
}
