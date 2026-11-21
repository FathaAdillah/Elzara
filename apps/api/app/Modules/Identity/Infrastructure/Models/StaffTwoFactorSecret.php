<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StaffTwoFactorSecretFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable|null $confirmed_at
 */
final class StaffTwoFactorSecret extends Model
{
    /** @use HasFactory<StaffTwoFactorSecretFactory> */
    use HasFactory;

    protected $table = 'staff_2fa_secrets';

    protected $guarded = [];

    protected $hidden = ['secret_encrypted', 'recovery_hashes'];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): StaffTwoFactorSecretFactory
    {
        return StaffTwoFactorSecretFactory::new();
    }
}
