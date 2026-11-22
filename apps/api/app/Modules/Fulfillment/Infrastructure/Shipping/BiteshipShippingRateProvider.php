<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Infrastructure\Shipping;

use App\Modules\Fulfillment\Contracts\InvalidDestination;
use App\Modules\Fulfillment\Contracts\ProviderUnavailable;
use App\Modules\Fulfillment\Contracts\ShippingRate;
use App\Modules\Fulfillment\Contracts\ShippingRateProvider;
use App\Modules\Fulfillment\Contracts\ShippingRateRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

final class BiteshipShippingRateProvider implements ShippingRateProvider
{
    public function rates(ShippingRateRequest $request): array
    {
        if ($request->originCountry !== 'ID' || $request->destinationCountry !== 'ID') {
            throw new InvalidDestination('Biteship supports domestic ID destinations only.');
        }
        $origin = Config::string('shipping.biteship.origin_postcode');
        $key = Config::string('shipping.biteship.key');
        if (! preg_match('/\A[0-9]{5}\z/', $origin) || $key === '') {
            throw new ProviderUnavailable('Biteship origin or credentials are not configured.');
        }
        try {
            $response = Http::withHeaders(['Authorization' => $key])->acceptJson()
                ->timeout(max(1, Config::integer('shipping.biteship.timeout_seconds')))
                ->post(rtrim(Config::string('shipping.biteship.base_url'), '/').'/v1/rates/couriers', [
                    'origin_postal_code' => (int) $origin, 'destination_postal_code' => (int) $request->postcode,
                    'couriers' => $request->courier ?? Config::string('shipping.biteship.couriers'),
                    'items' => [['name' => $request->packageTier, 'value' => 0, 'quantity' => 1, 'weight' => $request->weightGrams]],
                ]);
        } catch (ConnectionException $exception) {
            throw new ProviderUnavailable('Biteship connection failed.', previous: $exception);
        }
        if ($response->status() === 422 || $response->status() === 400) {
            throw new InvalidDestination('Biteship rejected the rate request.');
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['success'] ?? null) !== true || ! is_array($body['pricing'] ?? null)) {
            throw new ProviderUnavailable('Biteship returned an unsuccessful response.');
        }
        $rates = [];
        foreach ($body['pricing'] as $row) {
            if (! is_array($row) || ! is_int($row['price'] ?? null) || ! is_string($row['courier_code'] ?? null)
                || ! is_string($row['courier_service_code'] ?? null) || ! is_string($row['courier_service_name'] ?? null)) {
                throw new ProviderUnavailable('Biteship returned malformed pricing.');
            }
            if ($request->courier !== null && $row['courier_code'] !== $request->courier) {
                continue;
            }
            $min = $max = null;
            if (in_array($row['shipment_duration_unit'] ?? null, ['day', 'days'], true) && is_string($row['shipment_duration_range'] ?? null)
                && preg_match('/\A(\d+)(?:\s*-\s*(\d+))?\z/', $row['shipment_duration_range'], $matches)) {
                $min = (int) $matches[1];
                $max = (int) ($matches[2] ?? $matches[1]);
            }
            $rates[] = new ShippingRate($row['courier_code'], $row['courier_service_code'], $row['courier_service_name'], $row['price'], 'IDR', $min, $max, 'biteship');
        }

        return $rates;
    }
}
