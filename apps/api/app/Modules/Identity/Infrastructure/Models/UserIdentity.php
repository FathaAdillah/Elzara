<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Database\Factories\UserIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_user_id
 */
final class UserIdentity extends Model
{
    /** @use HasFactory<UserIdentityFactory> */
    use HasFactory;

    protected $table = 'user_identities';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'linked_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): UserIdentityFactory
    {
        return UserIdentityFactory::new();
    }
}
