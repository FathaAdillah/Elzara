<?php

declare(strict_types=1);

namespace App\Modules\Cart\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $variant_id
 * @property int $qty
 * @property int $unit_price_snapshot
 */
final class CartItem extends Model
{
    protected $table = 'cart_items';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['qty' => 'integer', 'unit_price_snapshot' => 'integer'];
    }
}
