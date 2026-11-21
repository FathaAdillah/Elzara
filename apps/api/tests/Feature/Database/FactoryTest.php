<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Content\Infrastructure\Models\LocalizedString;
use App\Modules\Content\Infrastructure\Models\Media;
use App\Modules\Identity\Infrastructure\Models\Address;
use App\Modules\Identity\Infrastructure\Models\CustomerProfile;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\StaffTwoFactorSecret;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserConsent;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Modules\Identity\Infrastructure\Security\StaffTwoFactor;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use App\Modules\Settings\Infrastructure\Models\FeatureFlagOverride;
use App\Modules\Settings\Infrastructure\Models\Setting;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FactoryTest extends TestCase
{
    use DatabaseTransactions;

    /** @return iterable<string, array{class-string<Model>}> */
    public static function models(): iterable
    {
        foreach ([User::class, Address::class, CustomerProfile::class, UserConsent::class, OtpChallenge::class, UserSession::class, UserIdentity::class, StaffTwoFactorSecret::class, Role::class, Permission::class, Media::class, LocalizedString::class, FeatureFlag::class, FeatureFlagOverride::class, Setting::class, AuditLog::class] as $class) {
            yield class_basename($class) => [$class];
        }
    }

    /** @param class-string<Model> $class */
    #[DataProvider('models')]
    public function test_factory_rows_satisfy_schema_constraints(string $class): void
    {
        $class::factory()->count(2)->create();

        $this->assertGreaterThanOrEqual(2, $class::query()->count());
    }

    public function test_user_states(): void
    {
        $operator = User::factory()->operator()->create();
        $customer = User::factory()->withProfile()->create();

        $this->assertTrue($operator->canAccessAdmin());
        $this->assertFalse($customer->canAccessAdmin());
        $this->assertTrue(password_verify(UserFactory::PASSWORD, (string) $customer->password_hash));
        $this->assertSame(1, CustomerProfile::query()->where('user_id', $customer->id)->count());
        $this->assertTrue(User::factory()->adminBypass()->create()->is_admin);
    }

    public function test_staff_two_factor_secret_is_readable_by_the_verifier(): void
    {
        $secret = StaffTwoFactorSecret::factory()->create();

        $this->assertTrue(app(StaffTwoFactor::class)->isConfirmed(User::query()->findOrFail($secret->user_id)));
    }
}
