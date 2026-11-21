<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Settings\Application\Queries\ListFeatureFlags;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
