<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ShippingTariffRules
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): int
    {
        $validated = Validator::make($attributes, [
            'courier_code' => 'required|string|max:32', 'service_code' => 'required|string|max:32',
            'origin_country' => 'required|string|regex:/\A[A-Z]{2}\z/', 'dest_province' => 'required|string|max:60',
            'package_tier' => 'required|string|max:24', 'weight_from_g' => 'required|integer|min:0',
            'weight_to_g' => 'nullable|integer|gt:weight_from_g', 'base_amount' => 'required|integer|min:0',
            'per_500g_amount' => 'required|integer|min:0', 'effective_from' => 'required|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from', 'created_by' => 'required|integer|min:1',
        ])->validate();
        $rule = array_intersect_key($attributes, $validated);

        return DB::transaction(function () use ($rule): int {
            $selector = array_intersect_key($rule, array_flip(['origin_country', 'dest_province', 'courier_code', 'service_code', 'package_tier']));
            ksort($selector);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [json_encode($selector, JSON_THROW_ON_ERROR)]);
            $existing = DB::table('shipping_tariff_rules')->where($selector)
                ->where(fn (Builder $q) => $q->whereNull('weight_to_g')->orWhere('weight_to_g', '>', $rule['weight_from_g']))
                ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $rule['effective_from']));
            if (($rule['weight_to_g'] ?? null) !== null) {
                $existing->where('weight_from_g', '<', $rule['weight_to_g']);
            }
            if (($rule['effective_to'] ?? null) !== null) {
                $existing->where('effective_from', '<=', $rule['effective_to']);
            }
            if ($existing->exists()) {
                throw ValidationException::withMessages(['weight_from_g' => ['Tariff weight and effective ranges overlap.']]);
            }

            return DB::table('shipping_tariff_rules')->insertGetId($rule);
        });
    }
}
