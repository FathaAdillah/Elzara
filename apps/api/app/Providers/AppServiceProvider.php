<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Audit\Application\DatabaseAuditRecorder;
use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Content\Application\I18n\LocalizationResolver;
use App\Modules\Content\Contracts\LocalizedText;
use App\Modules\Settings\Application\DatabaseSettingValueQuery;
use App\Modules\Settings\Application\FeatureFlagService;
use App\Modules\Settings\Contracts\FeatureFlagQuery;
use App\Modules\Settings\Contracts\SettingValueQuery;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditRecorder::class, DatabaseAuditRecorder::class);
        $this->app->bind(LocalizedText::class, LocalizationResolver::class);
        $this->app->bind(FeatureFlagQuery::class, FeatureFlagService::class);
        $this->app->bind(SettingValueQuery::class, DatabaseSettingValueQuery::class);
    }

    public function boot(): void {}
}
