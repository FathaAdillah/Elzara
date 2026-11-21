<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReferenceSeederTest extends TestCase
{
    use DatabaseTransactions;

    private const TABLES = ['roles' => 'id', 'permissions' => 'id', 'role_has_permissions' => 'role_id, permission_id', 'feature_flags' => 'id', 'feature_flag_overrides' => 'flag_code, env', 'settings' => 'id', 'localizations' => 'id'];

    public function test_running_twice_changes_no_rows(): void
    {
        $this->seed(ReferenceSeeder::class);
        $before = $this->snapshot();
        $this->seed(ReferenceSeeder::class);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(10, Role::query()->count());
        $this->assertTrue(FeatureFlag::query()->where('code', 'checkout.enabled')->value('default_enabled'));
        $this->assertSame(0, DB::table('model_has_roles')->count());
    }

    public function test_superadmin_holds_every_granular_permission_without_a_wildcard(): void
    {
        $this->seed(ReferenceSeeder::class);

        $this->assertSame(0, DB::table('permissions')->where('name', 'like', '%*%')->count());
        $superadmin = Role::findByName('superadmin', 'admin');
        $this->assertSame(DB::table('permissions')->where('guard_name', 'admin')->count(), $superadmin->permissions()->count());
    }

    public function test_reseeding_keeps_operator_edits(): void
    {
        $this->seed(ReferenceSeeder::class);
        FeatureFlag::query()->where('code', 'checkout.enabled')->update(['description' => 'Diubah operator', 'default_enabled' => false]);
        DB::table('settings')->where('key', 'wa.provider')->update(['value' => json_encode(['v' => 'qontak'])]);

        $this->seed(ReferenceSeeder::class);

        $flag = FeatureFlag::query()->where('code', 'checkout.enabled')->firstOrFail();
        $this->assertSame('Diubah operator', $flag->getAttribute('description'));
        $this->assertFalse($flag->default_enabled);
        $this->assertSame(['v' => 'qontak'], json_decode((string) DB::table('settings')->where('key', 'wa.provider')->value('value'), true));
    }

    public function test_checkout_kill_switch_copy_is_seeded_for_both_locales(): void
    {
        $this->seed(ReferenceSeeder::class);

        foreach (['id', 'en'] as $locale) {
            $this->assertSame(trans('checkout.disabled', [], $locale), DB::table('localizations')->where(['key' => 'checkout.disabled', 'group_name' => 'shop', 'locale' => $locale])->value('value'));
        }
        $this->assertSame(1, DB::table('localizations')->where(['key' => 'admin.flag.title', 'locale' => 'en', 'group_name' => 'admin'])->count());
    }

    private function snapshot(): string
    {
        $parts = [];
        foreach (self::TABLES as $table => $order) {
            $rows = DB::table($table)->orderByRaw($order)->get()->map(fn (object $row): string => (string) json_encode(get_object_vars($row)))->implode('|');
            $parts[] = $table.':'.sha1($rows);
        }

        return implode(';', $parts);
    }
}
