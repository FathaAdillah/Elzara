<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Content\Application\I18n\LocalizedStringRepository;
use App\Modules\Content\Infrastructure\Models\LocalizedString;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class KillSwitchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_disabled_rejects_before_validation_with_localized_problem(): void
    {
        Cache::flush();
        FeatureFlag::create(['code' => 'checkout.enabled', 'description' => 'Checkout', 'default_enabled' => false]);
        foreach (['id', 'en', 'en-US,en;q=0.9', 'fr'] as $language) {
            $locale = str_starts_with($language, 'en') ? 'en' : 'id';
            $this->postJson('/api/v1/orders', [], ['Accept-Language' => $language])->assertStatus(503)
                ->assertHeader('X-RateLimit-Policy', 'web')
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'urn:elzara:problem:server:checkout-disabled')
                ->assertJsonPath('code', 'CHECKOUT_DISABLED')
                ->assertJsonPath('retryable', true)
                ->assertJsonPath('detail', __('checkout.disabled', [], $locale));
        }
        LocalizedString::create(['key' => 'checkout.disabled', 'group_name' => 'shop', 'locale' => 'en', 'value' => 'DB maintenance']);
        app(LocalizedStringRepository::class)->invalidate('shop', 'en');
        $this->postJson('/api/v1/orders', [], ['Accept-Language' => 'en'])->assertJsonPath('detail', 'DB maintenance');
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_enabled_does_not_bypass_customer_authentication(): void
    {
        FeatureFlag::create(['code' => 'checkout.enabled', 'description' => 'Checkout', 'default_enabled' => true]);
        $this->postJson('/api/v1/orders', [])->assertUnauthorized();
        $this->assertSame(0, DB::table('orders')->count());
    }
}
