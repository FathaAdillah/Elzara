<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $password_hash
 * @property bool $is_operator
 * @property bool $is_admin
 * @property string $status
 * @property string $timezone
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use SoftDeletes;

    protected string $guard_name = 'admin';

    protected $table = 'users';

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'is_operator' => 'boolean',
            'is_admin' => 'boolean',
            'email_verified_at' => 'immutable_datetime',
        ];
    }

    public function getAuthPassword(): ?string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function canAccessAdmin(): bool
    {
        return $this->is_operator && $this->status === 'active';
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
