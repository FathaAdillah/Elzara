<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Identity\Infrastructure\Models\CustomerProfile;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DemoAccountSeeder extends Seeder
{
    public const OPERATOR_EMAIL = 'demo.operator@elzara.test';

    public const CUSTOMER_EMAIL = 'demo.customer@elzara.test';

    private ?string $password = null;

    public function run(): void
    {
        DemoEnvironment::assertNotProduction();

        DB::transaction(function (): void {
            $operator = $this->account(self::OPERATOR_EMAIL, 'Demo Operator', true);
            if ($operator->wasRecentlyCreated) {
                $operator->assignRole('superadmin');
            }

            $customer = $this->account(self::CUSTOMER_EMAIL, 'Demo Customer', false);
            CustomerProfile::query()->firstOrCreate(['user_id' => $customer->id], ['display_name' => 'Demo Customer', 'gender' => 'undisclosed']);
        });
    }

    /** Existing demo accounts keep their password and roles, so reseeding never undoes an operator's changes. */
    private function account(string $email, string $name, bool $operator): User
    {
        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            return $existing;
        }

        return User::query()->create([
            'email' => $email,
            'public_id' => (string) Str::ulid(),
            'name' => $name,
            'email_verified_at' => now(),
            'password_hash' => password_hash($this->password(), PASSWORD_ARGON2ID),
            'is_operator' => $operator,
            'status' => 'active',
        ]);
    }

    private function password(): string
    {
        if ($this->password === null) {
            $this->password = (string) env('DEMO_PASSWORD', '');
            if ($this->password === '') {
                $this->password = Str::password(20);
                $this->command?->warn("DEMO_PASSWORD kosong; password akun demo baru: {$this->password}");
            }
        }

        return $this->password;
    }
}
