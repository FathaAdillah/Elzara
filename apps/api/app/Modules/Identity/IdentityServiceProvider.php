<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Platform\Observability\Metrics;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(function (Authenticatable $user, string $ability): ?bool {
            if (! $user instanceof User || Auth::getDefaultDriver() !== 'admin') {
                return null;
            }
            if (Auth::guard('admin')->id() !== $user->id) {
                return null;
            }
            if ($user->is_admin) {
                $request = request();
                app(AuditRecorder::class)->record($user->id, 'rbac.bypass', 'users', $user->id, [], [],
                    'is_admin bypass: '.$ability, $request->attributes->getString('request_id', (string) $request->header('X-Request-Id')),
                    $request->ip(), (string) $request->userAgent());
                Metrics::increment('elzara_rbac_bypass_total');

                return true;
            }

            try {
                return $user->hasPermissionTo($ability, 'admin') ?: null;
            } catch (PermissionDoesNotExist) {
                return null;
            }
        });
    }
}
