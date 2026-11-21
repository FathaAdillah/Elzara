<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Database\Factories\UserConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property int $policy_version
 */
final class UserConsent extends Model
{
    /** @use HasFactory<UserConsentFactory> */
    use HasFactory;

    protected $table = 'user_consents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'granted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): UserConsentFactory
    {
        return UserConsentFactory::new();
    }
}
