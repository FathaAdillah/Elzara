<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateAdminOperator extends Command
{
    protected $signature = 'identity:admin:create {email} {name}';

    protected $description = 'Buat operator aktif baru untuk pendaftaran 2FA';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Pembuatan operator memerlukan terminal interaktif privat.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim($this->argument('email')));
        $name = trim($this->argument('name'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $name === '' || mb_strlen($name) > 120) {
            $this->error('Email atau nama operator tidak valid.');

            return self::FAILURE;
        }

        $password = $this->secret('Kata sandi baru (minimal 12 karakter)', false);
        if (! is_string($password) || mb_strlen($password) < 12 || mb_strlen($password) > 128
            || $password !== $this->secret('Ulangi kata sandi', false)) {
            $this->error('Kata sandi tidak valid atau konfirmasi berbeda.');

            return self::FAILURE;
        }

        $created = DB::table('users')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'is_operator' => true,
            'is_admin' => false,
            'status' => 'active',
        ]);

        if ($created !== 1) {
            $this->error('Akun belum dibuat. Periksa apakah email sudah dipakai.');

            return self::FAILURE;
        }

        $this->info('Operator dibuat. Konfirmasi TOTP dengan identity:admin:enroll-2fa sebelum login.');

        return self::SUCCESS;
    }
}
