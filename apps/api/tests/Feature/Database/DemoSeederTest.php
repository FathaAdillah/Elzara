<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Modules\Content\Infrastructure\Models\Media;
use App\Modules\Identity\Infrastructure\Models\User;
use Database\Seeders\Demo\DemoAccountSeeder;
use Database\Seeders\Demo\DemoMediaSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class DemoSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DemoSeeder tidak boleh berjalan di environment production');

        (new DemoSeeder)->run();
    }

    public function test_child_demo_seeders_refuse_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        foreach ([DemoAccountSeeder::class, DemoMediaSeeder::class] as $seeder) {
            try {
                (new $seeder)->run();
                $this->fail("{$seeder} ran in production");
            } catch (RuntimeException $e) {
                $this->assertSame('DemoSeeder tidak boleh berjalan di environment production', $e->getMessage());
            }
        }
        $this->assertDatabaseMissing('users', ['email' => DemoAccountSeeder::OPERATOR_EMAIL]);
    }

    public function test_reseeding_keeps_revoked_roles_and_existing_passwords(): void
    {
        Storage::fake(DemoMediaSeeder::DISK);
        $this->seed(DemoSeeder::class);
        $operator = User::query()->where('email', DemoAccountSeeder::OPERATOR_EMAIL)->firstOrFail();
        $operator->removeRole('superadmin');
        $hash = $operator->password_hash;

        $this->seed(DemoSeeder::class);

        $operator->refresh();
        $this->assertFalse($operator->hasRole('superadmin'));
        $this->assertSame($hash, $operator->password_hash);
    }

    public function test_database_seeder_never_calls_demo_seeder(): void
    {
        $this->assertStringNotContainsString('DemoSeeder', (string) file_get_contents(database_path('seeders/DatabaseSeeder.php')));
    }

    public function test_seeds_accounts_and_ten_media_rows_idempotently(): void
    {
        Storage::fake(DemoMediaSeeder::DISK);

        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $operator = User::query()->where('email', DemoAccountSeeder::OPERATOR_EMAIL)->firstOrFail();
        $this->assertTrue($operator->canAccessAdmin());
        $this->assertTrue($operator->hasRole('superadmin'));
        $customer = User::query()->where('email', DemoAccountSeeder::CUSTOMER_EMAIL)->firstOrFail();
        $this->assertFalse($customer->is_operator);
        $this->assertDatabaseHas('customer_profiles', ['user_id' => $customer->id]);

        $media = Media::query()->where('model_type', DemoMediaSeeder::MODEL_TYPE)->orderBy('model_id')->get();
        $this->assertCount(10, $media);
        $this->assertSame(range(1, 10), $media->pluck('model_id')->all());
        foreach ($media as $row) {
            $this->assertSame('approved', $row->getAttribute('moderation_status'));
            Storage::disk(DemoMediaSeeder::DISK)->assertExists($row->path);
            $this->assertSame($row->sha256, hash('sha256', (string) Storage::disk(DemoMediaSeeder::DISK)->get($row->path)));
        }
    }
}
