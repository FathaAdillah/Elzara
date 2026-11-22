<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Modules\Identity\Application\AdminSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdminPermission
{
    public function __construct(private AdminSession $sessions) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $this->sessions->activeUser($request);
        abort_unless($user->can($permission), 403);
        $request->attributes->set('admin_actor_id', $user->id);

        return $next($request);
    }
}
