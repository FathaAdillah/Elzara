<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Audit\Application\DatabaseAuditRecorder;
use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Billing\Application\DatabaseCheckoutInvoices;
use App\Modules\Billing\Contracts\CheckoutInvoices;
use App\Modules\Cart\Application\CheckoutService;
use App\Modules\Cart\Contracts\ReadyStockCheckout;
use App\Modules\Catalog\Application\CatalogScopes;
use App\Modules\Catalog\Application\DatabaseProductSearchSource;
use App\Modules\Catalog\Application\DatabaseSellableVariants;
use App\Modules\Catalog\Contracts\CategoryScopes;
use App\Modules\Catalog\Contracts\ProductSearch;
use App\Modules\Catalog\Contracts\ProductSearchSource;
use App\Modules\Catalog\Contracts\SellableVariants;
use App\Modules\Catalog\Infrastructure\Search\PostgresProductSearch;
use App\Modules\Content\Application\I18n\LocalizationResolver;
use App\Modules\Content\Contracts\LocalizedText;
use App\Modules\Fulfillment\Contracts\ShippingRateProvider;
use App\Modules\Fulfillment\Infrastructure\Shipping\BiteshipShippingRateProvider;
use App\Modules\Fulfillment\Infrastructure\Shipping\CachedShippingRateProvider;
use App\Modules\Fulfillment\Infrastructure\Shipping\ManualShippingRateProvider;
use App\Modules\Identity\Application\ActiveOrderAddressUsage;
use App\Modules\Identity\Application\AddressBook;
use App\Modules\Identity\Application\DeferredActiveOrderAddressUsage;
use App\Modules\Identity\Application\Queries\RoleRowScopes;
use App\Modules\Identity\Contracts\CustomerAddresses;
use App\Modules\Identity\Contracts\RowScopes;
use App\Modules\Inventory\Application\DatabaseStockReservations;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Payments\Application\DatabaseCheckoutPaymentIntents;
use App\Modules\Payments\Contracts\CheckoutPaymentIntents;
use App\Modules\Payments\Contracts\PaymentProvider;
use App\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use App\Modules\Search\Application\SyncProductSearchIndex;
use App\Modules\Settings\Application\DatabaseSettingValueQuery;
use App\Modules\Settings\Application\FeatureFlagService;
use App\Modules\Settings\Contracts\FeatureFlagQuery;
use App\Modules\Settings\Contracts\SettingValueQuery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditRecorder::class, DatabaseAuditRecorder::class);
        $this->app->bind(ProductSearch::class, PostgresProductSearch::class);
        $this->app->bind(SellableVariants::class, DatabaseSellableVariants::class);
        $this->app->bind(CustomerAddresses::class, AddressBook::class);
        $this->app->bind(ActiveOrderAddressUsage::class, DeferredActiveOrderAddressUsage::class);
        $this->app->bind(LocalizedText::class, LocalizationResolver::class);
        $this->app->bind(ShippingRateProvider::class, function (): ShippingRateProvider {
            $driver = config('shipping.driver');
            $provider = match ($driver) {
                'manual' => new ManualShippingRateProvider,
                'biteship' => new BiteshipShippingRateProvider,
                default => throw new \InvalidArgumentException('Unknown shipping driver.'),
            };

            return new CachedShippingRateProvider($provider, $driver.':'.hash('sha256', json_encode(config('shipping.biteship'), JSON_THROW_ON_ERROR)));
        });
        $this->app->bind(FeatureFlagQuery::class, FeatureFlagService::class);
        $this->app->bind(SettingValueQuery::class, DatabaseSettingValueQuery::class);
        $this->app->bind(ProductSearchSource::class, DatabaseProductSearchSource::class);
        $this->app->bind(RowScopes::class, RoleRowScopes::class);
        $this->app->bind(CategoryScopes::class, CatalogScopes::class);
        $this->app->bind(ReadyStockCheckout::class, CheckoutService::class);
        $this->app->bind(StockReservations::class, DatabaseStockReservations::class);
        $this->app->bind(CheckoutInvoices::class, DatabaseCheckoutInvoices::class);
        $this->app->bind(CheckoutPaymentIntents::class, DatabaseCheckoutPaymentIntents::class);
        $this->app->bind(PaymentProvider::class, FakePaymentProvider::class);
    }

    public function boot(): void
    {
        // Event outbox dikirim dengan nama event (DispatchDomainEvent); Search memproyeksikan ProductUpdated ke indeks.
        Event::listen('ProductUpdated', [SyncProductSearchIndex::class, 'handle']);
    }
}
