<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Platform\Http\CursorPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

// Sesi storefront: guard `web`, cookie elzara_web_session, satu baris user_sessions per sesi.
// Tidak membaca is_operator/is_admin: storefront hanya peduli status akun (Doc 11 §4.4).
final class WebSession
{
    public function open(Request $request, User $user): void
    {
        $guard = Auth::guard('web');
        $session = $request->session();

        try {
            // login() memigrasikan ID sesi; baris untuk ID lama dicabut agar tidak tersisa sebagai perangkat aktif.
            DB::table('user_sessions')
                ->where('session_hash', $this->hash($request))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
            $guard->login($user, false);
            $session->regenerateToken();
            $now = now();
            DB::transaction(function () use ($request, $user, $now): void {
                DB::table('user_sessions')->insert([
                    'user_id' => $user->getKey(),
                    'session_hash' => $this->hash($request),
                    'ip' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $user->forceFill(['last_login_at' => $now])->save();
            });
        } catch (Throwable $exception) {
            $guard->logout();
            $session->invalidate();
            $session->regenerateToken();

            throw $exception;
        }
    }

    public function activeUser(Request $request): User
    {
        $user = $this->user($request);
        abort_unless($user instanceof User, 401);

        return $user;
    }

    public function user(Request $request): ?User
    {
        if (! $request->hasSession()) {
            return null;
        }

        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            return null;
        }

        $now = now();
        $active = $user->status === 'active' && DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->where('session_hash', $this->hash($request))
            ->whereNull('revoked_at')
            ->update(['last_seen_at' => $now, 'updated_at' => $now]) === 1;

        if (! $active) {
            $this->close($request, $user);

            return null;
        }

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

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /** @return array{data: list<stdClass>, meta: array{limit: int, has_more: bool, next_cursor: ?string, prev_cursor: ?string}} */
    public function activePage(Request $request, User $user): array
    {
        $query = DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->select(['id', 'session_hash', 'device_label', 'user_agent', 'ip', 'last_seen_at', 'created_at']);

        return CursorPagination::paginate($request, $query, ['id' => 'desc']);
    }

    // Hanya baris milik $user yang bisa dicabut; baris akun lain tidak terlihat sama sekali.
    public function revoke(User $user, string $sessionHash): bool
    {
        return DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->where('session_hash', $sessionHash)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]) === 1;
    }

    public function hash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }
}
