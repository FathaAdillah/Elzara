<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Infrastructure\Shipping;

use App\Modules\Fulfillment\Contracts\ShippingRate;
use App\Modules\Fulfillment\Contracts\ShippingRateProvider;
use App\Modules\Fulfillment\Contracts\ShippingRateRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class CachedShippingRateProvider implements ShippingRateProvider
{
    public function __construct(private readonly ShippingRateProvider $inner, private readonly string $namespace) {}

    public function rates(ShippingRateRequest $request): array
    {
        if ($request->fresh) {
            return $this->inner->rates($request);
        }

        $destination = json_encode([$request->destinationCountry, $request->province, $request->city, $request->district, $request->postcode], JSON_THROW_ON_ERROR);
        $key = implode('|', [$request->originCountry, hash('sha256', $this->namespace.'|'.$destination), $request->weightGrams, $request->packageTier, $request->courier ?? '*']);
        $cached = DB::table('shipping_rates_cache')->where('cache_key', $key)->where('expires_at', '>', now())->first();
        if ($cached !== null) {
            if (! is_string($cached->rates)) {
                throw new \UnexpectedValueException('Invalid cached rate payload.');
            }
            /** @var list<array{courierCode: string, serviceCode: string, serviceName: string, amountMinor: int, currency: string, etaMinDays: ?int, etaMaxDays: ?int, provider: string}> $rows */
            $rows = json_decode($cached->rates, true, 512, JSON_THROW_ON_ERROR);

            return array_map(static fn (array $row): ShippingRate => new ShippingRate(...$row), $rows);
        }
        $rates = $this->inner->rates($request);
        $now = now();
        DB::table('shipping_rates_cache')->upsert([[
            'cache_key' => $key, 'amount' => $rates === [] ? 0 : min(array_map(static fn (ShippingRate $rate): int => $rate->amountMinor, $rates)),
            'provider' => $rates[0]->provider ?? explode(':', $this->namespace)[0], 'rates' => json_encode($rates, JSON_THROW_ON_ERROR),
            'fetched_at' => $now, 'expires_at' => $now->copy()->addSeconds(max(1, Config::integer('shipping.cache_ttl_seconds'))),
        ]], ['cache_key'], ['amount', 'provider', 'rates', 'fetched_at', 'expires_at']);

        return $rates;
    }
}
