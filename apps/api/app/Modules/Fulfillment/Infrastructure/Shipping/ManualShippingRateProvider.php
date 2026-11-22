<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Infrastructure\Shipping;

use App\Modules\Fulfillment\Contracts\ProviderUnavailable;
use App\Modules\Fulfillment\Contracts\ShippingRate;
use App\Modules\Fulfillment\Contracts\ShippingRateProvider;
use App\Modules\Fulfillment\Contracts\ShippingRateRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

final class ManualShippingRateProvider implements ShippingRateProvider
{
    public function rates(ShippingRateRequest $request): array
    {
        $query = DB::table('shipping_tariff_rules as r')->join('courier_services as c', function (JoinClause $join) use ($request): void {
            $join->on('c.courier_code', '=', 'r.courier_code')->on('c.service_code', '=', 'r.service_code')->where('c.country', $request->destinationCountry);
        })->where('c.is_active', true)->where('r.origin_country', $request->originCountry)
            ->whereIn('r.dest_province', ['*', $request->province])->where('r.package_tier', $request->packageTier)
            ->where('r.weight_from_g', '<=', $request->weightGrams)
            ->where(fn (Builder $q) => $q->whereNull('r.weight_to_g')->orWhere('r.weight_to_g', '>', $request->weightGrams))
            ->where('r.effective_from', '<=', now()->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', now()->toDateString()));
        if ($request->courier !== null) {
            $query->where('r.courier_code', $request->courier);
        }
        $rates = [];
        foreach ($query->orderByRaw("CASE WHEN r.dest_province = '*' THEN 1 ELSE 0 END")->orderBy('r.id')->get(['r.*', 'c.name']) as $rule) {
            $courier = $this->text($rule, 'courier_code');
            $service = $this->text($rule, 'service_code');
            $key = $courier.'|'.$service;
            $rates[$key] ??= new ShippingRate($courier, $service, $this->text($rule, 'name'),
                $this->integer($rule, 'base_amount') + (int) ceil(max(0, $request->weightGrams - $this->integer($rule, 'weight_from_g')) / 500) * $this->integer($rule, 'per_500g_amount'),
                'IDR', null, null, 'manual');
        }

        return array_values($rates);
    }

    private function text(stdClass $row, string $field): string
    {
        if (! is_string($row->{$field})) {
            throw new ProviderUnavailable('Invalid manual tariff field.');
        }

        return $row->{$field};
    }

    private function integer(stdClass $row, string $field): int
    {
        $value = $row->{$field};
        if (! is_int($value) && ! is_string($value)) {
            throw new ProviderUnavailable('Invalid manual tariff amount.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($integer === false) {
            throw new ProviderUnavailable('Invalid manual tariff amount.');
        }

        return $integer;
    }
}
