<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'users';

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'is_operator' => 'boolean',
            'email_verified_at' => 'immutable_datetime',
        ];
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
}
