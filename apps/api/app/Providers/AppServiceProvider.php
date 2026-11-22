<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Audit\Application\DatabaseAuditRecorder;
use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Application\DatabaseProductSearchSource;
use App\Modules\Catalog\Contracts\ProductSearchSource;
use App\Modules\Content\Application\I18n\LocalizationResolver;
use App\Modules\Content\Contracts\LocalizedText;
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
        $this->app->bind(LocalizedText::class, LocalizationResolver::class);
        $this->app->bind(FeatureFlagQuery::class, FeatureFlagService::class);
        $this->app->bind(SettingValueQuery::class, DatabaseSettingValueQuery::class);
        $this->app->bind(ProductSearchSource::class, DatabaseProductSearchSource::class);
    }

    public function boot(): void
    {
        // Event outbox dikirim dengan nama event (DispatchDomainEvent); Search memproyeksikan ProductUpdated ke indeks.
        Event::listen('ProductUpdated', [SyncProductSearchIndex::class, 'handle']);
    }
}
