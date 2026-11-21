<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Modules\Identity\Infrastructure\Models\CustomerProfile;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class DemoAccountSeeder extends Seeder
{
    public const OPERATOR_EMAIL = 'demo.operator@elzara.test';

    public const CUSTOMER_EMAIL = 'demo.customer@elzara.test';

    public function run(): void
    {
        $password = (string) env('DEMO_PASSWORD', '');
        if ($password === '') {
            $password = Str::password(20);
            $this->command?->warn("DEMO_PASSWORD kosong; password demo sekali pakai: {$password}");
        }

        $operator = $this->account(self::OPERATOR_EMAIL, 'Demo Operator', $password, true);
        if (! $operator->hasRole('superadmin')) {
            $operator->assignRole('superadmin');
        }

        $customer = $this->account(self::CUSTOMER_EMAIL, 'Demo Customer', $password, false);
        CustomerProfile::query()->firstOrCreate(['user_id' => $customer->id], ['display_name' => 'Demo Customer', 'gender' => 'undisclosed']);
    }

    private function account(string $email, string $name, string $password, bool $operator): User
    {
        return User::query()->firstOrCreate(['email' => $email], [
            'public_id' => (string) Str::ulid(),
            'name' => $name,
            'email_verified_at' => now(),
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'is_operator' => $operator,
            'status' => 'active',
        ]);
    }
}
