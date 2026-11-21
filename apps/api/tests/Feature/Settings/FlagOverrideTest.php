<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Settings\Contracts\FeatureFlagQuery;
use App\Modules\Settings\Domain\MissingFeatureFlag;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use App\Modules\Settings\Infrastructure\Models\FeatureFlagOverride;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class FlagOverrideTest extends TestCase
{
    use DatabaseTransactions;

    public function test_defaults_environment_overrides_and_rollout(): void
    {
        $flags = app(FeatureFlagQuery::class);
        FeatureFlag::create(['code' => 'test.flag', 'description' => 'Test', 'default_enabled' => true]);
        $user = DB::table('users')->insertGetId(['public_id' => str_repeat('a', 26), 'name' => 'Test', 'email' => 'flags@example.test']);
        FeatureFlagOverride::create(['flag_code' => 'test.flag', 'env' => 'prod', 'enabled' => false, 'updated_by' => $user]);
        $this->assertTrue($flags->enabled('test.flag'));
        $this->app->instance('env', 'production');
        $this->assertFalse($flags->enabled('test.flag'));
        $override = FeatureFlagOverride::query()->where('flag_code', 'test.flag')->where('env', 'prod')->firstOrFail();
        $override->update(['enabled' => true, 'rollout_percent' => 0]);
        foreach ([null, 1, 'customer'] as $subject) {
            $this->assertFalse($flags->enabled('test.flag', $subject));
        }
        $override->update(['rollout_percent' => 47]);
        foreach ([null, 1, 'customer', 999] as $subject) {
            $this->assertSame(crc32((string) ($subject ?? 'anon')) % 100 < 47, $flags->enabled('test.flag', $subject));
        }
        $this->app->instance('env', 'staging');
        $this->assertTrue($flags->enabled('test.flag'));
        FeatureFlag::query()->where('code', 'test.flag')->update(['default_enabled' => false]);
        $this->assertFalse($flags->enabled('test.flag'));
    }

    public function test_unknown_flag_maps_to_existing_not_found_problem(): void
    {
        Route::get('/api/v1/__test/flag', fn () => response()->json(app(FeatureFlagQuery::class)->enabled('absent')));
        $this->getJson('/api/v1/__test/flag')->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND')->assertJsonPath('type', 'urn:elzara:problem:not-found:resource');
    }

    public function test_unknown_flag_throws(): void
    {
        $this->expectException(MissingFeatureFlag::class);
        app(FeatureFlagQuery::class)->enabled('absent');
    }
}
