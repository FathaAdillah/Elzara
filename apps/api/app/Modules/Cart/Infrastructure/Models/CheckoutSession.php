<?php

declare(strict_types=1);

namespace App\Modules\Cart\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $cart_id
 * @property string $code
 * @property string $status
 * @property int $subtotal
 * @property int $shipping_estimate
 * @property array<string,mixed> $snapshot
 * @property Carbon $expires_at
 */
final class CheckoutSession extends Model
{
    protected $table = 'checkout_sessions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'snapshot' => 'array', 'subtotal' => 'integer', 'shipping_estimate' => 'integer'];
    }
}
