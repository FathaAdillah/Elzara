<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Settings\Application\Queries\ListFeatureFlags;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use App\Modules\Settings\Infrastructure\Models\FeatureFlagOverride;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicFeatureFlagsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_only_six_allowlisted_flags_are_public_and_not_cacheable(): void
    {
        foreach ([...ListFeatureFlags::PUBLIC_CODES, 'auth.social_google'] as $code) {
            FeatureFlag::create(['code' => $code, 'description' => $code, 'default_enabled' => true]);
        }
        $response = $this->getJson('/api/v1/feature-flags')->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame(array_map(fn ($code) => ['code' => $code, 'enabled' => true], ListFeatureFlags::PUBLIC_CODES), $response->json('data'));
        $this->assertStringNotContainsString('auth.social_google', $response->getContent());
    }

    public function test_missing_reference_data_returns_not_found_not_an_enabled_default(): void
    {
        $this->getJson('/api/v1/feature-flags')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_public_flags_and_checkout_use_the_same_authenticated_rollout_subject(): void
    {
        foreach (ListFeatureFlags::PUBLIC_CODES as $code) {
            FeatureFlag::create(['code' => $code, 'description' => $code, 'default_enabled' => true]);
        }
        do {
            $id = DB::table('users')->insertGetId(['public_id' => (string) Str::ulid(), 'name' => 'Shopper', 'email' => Str::ulid().'@example.test']);
        } while (crc32((string) $id) % 100 >= 50);
        $this->assertGreaterThanOrEqual(50, crc32('anon') % 100);
        FeatureFlagOverride::create(['flag_code' => 'checkout.enabled', 'env' => 'local', 'enabled' => true, 'rollout_percent' => 50, 'updated_by' => $id]);
        $this->getJson('/api/v1/feature-flags')->assertJsonPath('data.0.enabled', false);
        $this->postJson('/api/v1/orders', [])->assertStatus(503);

        $this->actingAs(new GenericUser(['id' => $id]));
        Bus::fake();
        $this->getJson('/api/v1/feature-flags')->assertJsonPath('data.0.enabled', true);
        $this->postJson('/api/v1/orders', ['items' => [['id' => 1]]])->assertStatus(202);
    }
}
