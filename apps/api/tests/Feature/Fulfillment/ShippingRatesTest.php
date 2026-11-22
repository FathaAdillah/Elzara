<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Modules\Fulfillment\Application\ShippingTariffRules;
use App\Modules\Fulfillment\Contracts\InvalidDestination;
use App\Modules\Fulfillment\Contracts\ProviderUnavailable;
use App\Modules\Fulfillment\Contracts\ShippingRateProvider;
use App\Modules\Fulfillment\Contracts\ShippingRateRequest;
use App\Modules\Fulfillment\Infrastructure\Shipping\BiteshipShippingRateProvider;
use App\Modules\Fulfillment\Infrastructure\Shipping\CachedShippingRateProvider;
use App\Modules\Fulfillment\Infrastructure\Shipping\ManualShippingRateProvider;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ShippingRatesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'shipping.biteship.key' => 'test', 'shipping.biteship.origin_postcode' => '10110']);
        Http::preventStrayRequests();
    }

    public static function drivers(): array
    {
        return [['manual'], ['biteship']];
    }

    private function request(int $weight = 500, ?string $courier = null): ShippingRateRequest
    {
        return new ShippingRateRequest('ID', 'Jakarta', 'Jakarta Pusat', 'Gambir', '10110', $weight, 'buku', $courier);
    }

    private function rule(array $changes = []): array
    {
        return array_replace(['courier_code' => 'jne', 'service_code' => 'reg', 'origin_country' => 'ID', 'dest_province' => 'Jakarta', 'package_tier' => 'buku', 'weight_from_g' => 0, 'weight_to_g' => 1000, 'base_amount' => 10000, 'per_500g_amount' => 1000, 'effective_from' => now()->subDay()->toDateString(), 'effective_to' => null, 'created_by' => User::factory()->create()->id], $changes);
    }

    private function manual(): ManualShippingRateProvider
    {
        DB::table('courier_services')->insert(['courier_code' => 'jne', 'service_code' => 'reg', 'name' => 'Regular']);
        app(ShippingTariffRules::class)->create($this->rule());

        return new ManualShippingRateProvider;
    }

    private function fakeRates(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'pricing' => [['courier_code' => 'jne', 'courier_service_code' => 'reg', 'courier_service_name' => 'Regular', 'price' => 11000, 'duration' => '2 - 3 days', 'shipment_duration_unit' => 'days', 'shipment_duration_range' => '2 - 3']]])]);
    }

    #[DataProvider('drivers')]
    public function test_shared_driver_contract_returns_typed_minor_unit_rates_and_empty_selection(string $driver): void
    {
        $this->fakeRates();
        $provider = $driver === 'manual' ? $this->manual() : new BiteshipShippingRateProvider;
        $rates = $provider->rates($this->request());
        $this->assertCount(1, $rates);
        $this->assertSame('jne', $rates[0]->courierCode);
        $this->assertSame('reg', $rates[0]->serviceCode);
        $this->assertSame('Regular', $rates[0]->serviceName);
        $this->assertSame(11000, $rates[0]->amountMinor);
        $this->assertSame('IDR', $rates[0]->currency);
        $this->assertSame($driver, $rates[0]->provider);
        $this->assertSame($driver === 'manual' ? null : 2, $rates[0]->etaMinDays);
        $this->assertSame($driver === 'manual' ? null : 3, $rates[0]->etaMaxDays);
        $this->assertSame([], $provider->rates($this->request(courier: 'jnt')));
    }

    public function test_manual_filters_boundaries_active_services_dates_and_wildcard_precedence(): void
    {
        $provider = $this->manual();
        app(ShippingTariffRules::class)->create($this->rule(['dest_province' => '*', 'base_amount' => 20000]));
        $this->assertSame(11000, $provider->rates($this->request())[0]->amountMinor);
        $this->assertSame([], $provider->rates($this->request(1000)));
        DB::table('shipping_tariff_rules')->update(['effective_to' => now()->subDays(2)->toDateString(), 'effective_from' => now()->subDays(3)->toDateString()]);
        $this->assertSame([], $provider->rates($this->request()));
        DB::table('shipping_tariff_rules')->update(['effective_from' => now()->toDateString(), 'effective_to' => null]);
        DB::table('courier_services')->update(['is_active' => false]);
        $this->assertSame([], $provider->rates($this->request()));
    }

    public function test_overlapping_rules_rejected_but_touching_weight_ranges_and_disjoint_dates_allowed(): void
    {
        $rules = app(ShippingTariffRules::class);
        $rule = $this->rule();
        $rules->create($rule);
        $rules->create(array_replace($rule, ['weight_from_g' => 1000, 'weight_to_g' => null]));
        $rules->create(array_replace($rule, ['effective_from' => now()->subDays(4)->toDateString(), 'effective_to' => now()->subDays(2)->toDateString()]));
        $this->expectException(ValidationException::class);
        $rules->create(array_replace($rule, ['weight_from_g' => 500, 'weight_to_g' => null]));
    }

    public function test_cache_miss_hit_expiry_empty_lists_and_selector_isolation(): void
    {
        $this->fakeRates();
        $provider = new CachedShippingRateProvider(new BiteshipShippingRateProvider, 'biteship');
        $first = $provider->rates($this->request());
        $this->assertEquals($first, $provider->rates($this->request()));
        Http::assertSentCount(1);
        $provider->rates($this->request(501));
        $this->assertSame([], $provider->rates($this->request(courier: 'jnt')));
        $this->assertSame([], $provider->rates($this->request(courier: 'jnt')));
        Http::assertSentCount(3);
        $this->travel(1800)->seconds();
        $provider->rates($this->request());
        Http::assertSentCount(4);
        $this->assertSame(3, DB::table('shipping_rates_cache')->count());
    }

    public function test_failures_never_cached_and_recovery_is_retried(): void
    {
        Http::fake(['*' => Http::sequence()->pushStatus(503)->push(['success' => true, 'pricing' => []])]);
        $provider = new CachedShippingRateProvider(new BiteshipShippingRateProvider, 'biteship');
        try {
            $provider->rates($this->request());
            $this->fail('Expected unavailable');
        } catch (ProviderUnavailable) {
            $this->assertSame(0, DB::table('shipping_rates_cache')->count());
        }
        $this->assertSame([], $provider->rates($this->request()));
        Http::assertSentCount(2);
    }

    public static function badResponses(): array
    {
        return [[503, [], ProviderUnavailable::class], [200, ['success' => true, 'pricing' => [['price' => 'not money']]], ProviderUnavailable::class], [422, [], InvalidDestination::class], [200, ['success' => false], ProviderUnavailable::class]];
    }

    #[DataProvider('badResponses')]
    public function test_biteship_maps_provider_errors(int $status, array $body, string $exception): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $this->expectException($exception);
        (new BiteshipShippingRateProvider)->rates($this->request());
    }

    public function test_biteship_connection_failure(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $this->expectException(ProviderUnavailable::class);
        (new BiteshipShippingRateProvider)->rates($this->request());
    }

    public function test_biteship_posts_configured_origin_weight_and_filter(): void
    {
        $this->fakeRates();
        (new BiteshipShippingRateProvider)->rates($this->request(courier: 'jne'));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.biteship.com/v1/rates/couriers' && $request['origin_postal_code'] === 10110 && $request['destination_postal_code'] === 10110 && $request['items'][0]['weight'] === 500 && $request['couriers'] === 'jne' && $request->hasHeader('Authorization', 'test'));
    }

    public function test_biteship_rejects_foreign_origin_without_network(): void
    {
        $this->expectException(InvalidDestination::class);
        (new BiteshipShippingRateProvider)->rates(new ShippingRateRequest('JP', 'Jakarta', 'Jakarta', 'Gambir', '10110', 500, 'buku'));
    }

    public function test_invalid_destination_rejected(): void
    {
        $this->expectException(InvalidDestination::class);
        new ShippingRateRequest('ID', '', '', '', 'wrong', 0, 'buku');
    }

    public function test_missing_biteship_configuration_is_unavailable(): void
    {
        config(['shipping.biteship.key' => '']);
        $this->expectException(ProviderUnavailable::class);
        (new BiteshipShippingRateProvider)->rates($this->request());
    }

    public function test_foreign_destination_rejected_by_biteship(): void
    {
        $this->expectException(InvalidDestination::class);
        (new BiteshipShippingRateProvider)->rates(new ShippingRateRequest('ID', 'Tokyo', 'Tokyo', 'Chiyoda', '1000001', 500, 'buku', destinationCountry: 'JP'));
    }

    public function test_cache_preserves_multiple_services_and_etas(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'pricing' => [
            ['courier_code' => 'jne', 'courier_service_code' => 'reg', 'courier_service_name' => 'Regular', 'price' => 11000, 'shipment_duration_unit' => 'days', 'shipment_duration_range' => '2 - 3'],
            ['courier_code' => 'jne', 'courier_service_code' => 'yes', 'courier_service_name' => 'Express', 'price' => 22000, 'shipment_duration_unit' => 'days', 'shipment_duration_range' => '1'],
        ]])]);
        $provider = new CachedShippingRateProvider(new BiteshipShippingRateProvider, 'biteship');
        $rates = $provider->rates($this->request());
        $this->assertCount(2, $rates);
        $this->assertEquals($rates, $provider->rates($this->request()));
        $this->assertSame(1, $rates[1]->etaMaxDays);
        Http::assertSentCount(1);
    }

    public function test_default_binding_and_configured_driver(): void
    {
        $this->assertInstanceOf(CachedShippingRateProvider::class, app(ShippingRateProvider::class));
        config(['shipping.driver' => 'biteship']);
        $this->fakeRates();
        $this->assertSame('biteship', app(ShippingRateProvider::class)->rates($this->request())[0]->provider);
    }
}
