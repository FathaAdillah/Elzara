<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AdminSession
{
    private const LOCAL_PASSWORD_ONLY = 'admin.local_password_only';

    public function allowsLocalPasswordOnly(User $user): bool
    {
        $email = config('auth.local_admin_password_only_email');

        return app()->environment('local') && is_string($email) && $email !== ''
            && strcasecmp($email, $user->email) === 0;
    }

    public function open(Request $request, User $user, bool $localPasswordOnly = false): void
    {
        $guard = Auth::guard('admin');
        $session = $request->session();

        try {
            $guard->login($user, false);
            $session->put(self::LOCAL_PASSWORD_ONLY, $localPasswordOnly);
            $session->regenerateToken();
            $now = now();
            DB::transaction(function () use ($request, $user, $now): void {
                DB::table('user_sessions')->insert([
                    'user_id' => $user->getKey(),
                    'session_hash' => $this->hash($request),
                    'ip' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                    'last_seen_at' => $now,
                ]);
                $user->forceFill(['last_login_at' => $now])->save();
            });
        } catch (Throwable $exception) {
            $guard->logout();
            $session->invalidate();
            $session->regenerateToken();
            throw $exception;
        }

        $session->setName(SelectAdminSession::AUTH_COOKIE);
        $request->attributes->set('expire_admin_cookie', SelectAdminSession::CHALLENGE_COOKIE);
    }

    public function activeUser(Request $request): User
    {
        abort_unless($request->hasSession(), 401);

        $user = Auth::guard('admin')->user();
        if (! $user instanceof User) {
            $request->attributes->set('expire_admin_cookie', SelectAdminSession::AUTH_COOKIE);
            abort(401);
        }

        $factorValid = $request->session()->get(self::LOCAL_PASSWORD_ONLY) === true
            ? $this->allowsLocalPasswordOnly($user)
            : DB::table('staff_2fa_secrets')->where('user_id', $user->getKey())->whereNotNull('confirmed_at')->exists();
        if (! $user->canAccessAdmin() || ! $factorValid) {
            $this->close($request, $user);
            abort(401);
        }

        $now = now();
        $active = DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->where('session_hash', $this->hash($request))
            ->whereNull('revoked_at')
            ->update(['last_seen_at' => $now, 'updated_at' => $now]);
        if ($active !== 1) {
            $this->close($request, $user);
            abort(401);
        }

        Auth::shouldUse('admin');

        return $user;
    }

    public function close(Request $request, User $user): void
    {
        $now = now();
        DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->where('session_hash', $this->hash($request))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'updated_at' => $now]);

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->attributes->set('expire_admin_cookie', SelectAdminSession::AUTH_COOKIE);
    }

    private function hash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }
}
