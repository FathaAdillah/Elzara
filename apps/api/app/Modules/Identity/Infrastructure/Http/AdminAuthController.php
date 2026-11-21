<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Security\StaffTwoFactor;
use App\Platform\Http\StrictValidation;
use App\Platform\Http\WireTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class AdminAuthController
{
    private const PENDING = 'admin.login_challenge';

    private const MINUTES = 5;

    private const MAX_FACTOR_ATTEMPTS = 5;

    private const DUMMY_PASSWORD = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function __construct(private readonly StaffTwoFactor $factors, private readonly AdminSession $sessions) {}

    public function login(Request $request): JsonResponse
    {
        /** @var array{email: string, password: string} $data */
        $data = StrictValidation::validate($request, [
            'email' => 'required|string|email|max:254',
            'password' => 'required|string|max:128',
        ]);

        $session = $request->session();
        $session->forget(self::PENDING);
        $email = mb_strtolower($data['email']);
        $rateKey = 'admin-login-credentials:'.hash('sha256', $email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            abort(429, '', ['Retry-After' => (string) RateLimiter::availableIn($rateKey)]);
        }
        RateLimiter::hit($rateKey, 60);

        $user = User::query()->where('email', $email)->first();
        $passwordHash = $user?->getAuthPassword();
        $matches = password_verify($data['password'], $passwordHash ?? self::DUMMY_PASSWORD);
        abort_unless($user !== null && $passwordHash !== null && $matches, 401);
        abort_unless($user->canAccessAdmin(), 403);

        if ($this->sessions->allowsLocalPasswordOnly($user)) {
            RateLimiter::clear($rateKey);
            $this->sessions->open($request, $user, localPasswordOnly: true);

            return $this->userResponse($user, ['two_factor_required' => false]);
        }

        abort_unless($this->factors->isConfirmed($user), 403);
        RateLimiter::clear($rateKey);
        $session->regenerate(true);
        $nonce = bin2hex(random_bytes(24));
        $challenge = $nonce.'.'.hash_hmac('sha256', $nonce, Config::string('app.key'));
        $expires = now()->addMinutes(self::MINUTES);
        $session->put(self::PENDING, [
            'user_id' => $user->id,
            'digest' => hash('sha256', $challenge),
            'expires_at' => $expires->getTimestamp(),
            'attempts' => 0,
        ]);

        return response()->json([
            'two_factor_required' => true,
            'challenge_id' => $challenge,
            'expires_at' => WireTime::format($expires),
        ])->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request): JsonResponse
    {
        /** @var array{challenge_id: string, code: string} $data */
        $data = StrictValidation::validate($request, [
            'challenge_id' => 'required|string|max:128',
            'code' => 'required|string|min:6|max:12',
        ]);
        $session = $request->session();
        /** @var array{user_id: int, digest: string, expires_at: int, attempts: int}|null $pending */
        $pending = $session->get(self::PENDING);
        if (! is_array($pending) || $pending['expires_at'] <= now()->getTimestamp()) {
            $session->forget(self::PENDING);
            abort(401);
        }

        if ($pending['attempts'] >= self::MAX_FACTOR_ATTEMPTS) {
            abort(429, '', ['Retry-After' => (string) max(1, $pending['expires_at'] - now()->getTimestamp())]);
        }
        $pending['attempts']++;
        $session->put(self::PENDING, $pending);
        abort_unless(hash_equals($pending['digest'], hash('sha256', $data['challenge_id'])), 401);

        $user = User::query()->find($pending['user_id']);
        abort_unless($user !== null && $user->canAccessAdmin() && $this->factors->consume($user, $data['code']), 401);

        $session->forget(self::PENDING);
        $this->sessions->open($request, $user);

        return $this->userResponse($user);
    }

    public function session(Request $request): JsonResponse
    {
        return $this->userResponse($this->sessions->activeUser($request));
    }

    public function logout(Request $request): Response
    {
        $this->sessions->close($request, $this->sessions->activeUser($request));

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    /** @param array{two_factor_required?: false} $loginState */
    private function userResponse(User $user, array $loginState = []): JsonResponse
    {
        return response()->json([
            'authenticated' => true,
            'user' => [
                'user_id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at === null ? null : WireTime::format($user->email_verified_at),
                'timezone' => $user->timezone,
            ],
        ] + $loginState)->header('Cache-Control', 'no-store');
    }
}
