<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Database\Factories\CustomerProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $display_name
 * @property array<string, mixed> $external_ids
 */
final class CustomerProfile extends Model
{
    /** @use HasFactory<CustomerProfileFactory> */
    use HasFactory;

    protected $table = 'customer_profiles';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'external_ids' => 'array',
            'birthday' => 'immutable_date',
        ];
    }

    protected static function newFactory(): CustomerProfileFactory
    {
        return CustomerProfileFactory::new();
    }
}
