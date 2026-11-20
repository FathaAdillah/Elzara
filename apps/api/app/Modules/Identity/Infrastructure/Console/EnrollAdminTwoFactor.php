<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Security\StaffTwoFactor;
use Illuminate\Console\Command;
use OTPHP\TOTP;
use Symfony\Component\Clock\NativeClock;

final class EnrollAdminTwoFactor extends Command
{
    protected $signature = 'identity:admin:enroll-2fa {email}';

    protected $description = 'Konfirmasi faktor TOTP pertama untuk operator aktif';

    public function handle(StaffTwoFactor $factors): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Pendaftaran 2FA memerlukan terminal interaktif privat.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();
        $email = $user->email ?? '';
        if ($user === null || ! $user->canAccessAdmin() || $user->getAuthPassword() === null || $email === '') {
            $this->error('Hanya operator aktif dengan kata sandi yang dapat mendaftar.');

            return self::FAILURE;
        }
        if ($factors->isConfirmed($user)) {
            $this->error('Operator ini sudah memiliki 2FA terkonfirmasi.');

            return self::FAILURE;
        }

        $otp = TOTP::generate(new NativeClock);
        $otp->setLabel($email);
        $otp->setIssuer('Elzara Admin');
        $this->line('Masukkan URI ini di aplikasi autentikator pada perangkat operator.');
        $this->line($otp->getProvisioningUri());
        $code = $this->secret('Kode TOTP saat ini', false);

        $recoveryCodes = is_string($code) ? $factors->enroll($user, $otp, $code) : null;
        if ($recoveryCodes === null) {
            $this->error('Kode ditolak atau status operator berubah. Faktor belum disimpan.');

            return self::FAILURE;
        }

        $this->line('Simpan kode pemulihan secara privat. Setiap kode dapat dipakai satu kali.');
        foreach ($recoveryCodes as $recoveryCode) {
            $this->line($recoveryCode);
        }

        return self::SUCCESS;
    }
}
