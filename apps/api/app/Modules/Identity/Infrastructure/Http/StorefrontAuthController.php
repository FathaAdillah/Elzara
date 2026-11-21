<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Modules\Identity\Infrastructure\Mail\OneTimeCodeMail;
use App\Modules\Identity\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Auth storefront (Doc 12 §2.2 grup S): email+kata sandi, OTP email, reset kata sandi.
// Setiap akun aktif boleh masuk, termasuk operator; is_operator/is_admin tidak dibaca di sini.
final class StorefrontAuthController
{
    private const OTP_PURPOSES = ['login', 'verify_email'];

    private const DUMMY_PASSWORD = '$2y$12$4Oe8Yfb9YvX1t0Yb1o5Ywu1NnH6eQ3m5yJc3o5n2kJ8l9q2r0s1tC';

    public function __construct(
        private readonly WebSession $sessions,
        private readonly OneTimeSecrets $secrets,
        private readonly StorefrontAccounts $accounts,
    ) {}

    public function register(Request $request): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, locale?: string} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'locale' => ['sometimes', 'string', 'in:id,en'],
        ]);
        $this->limit($request, 'register', (string) $request->ip(), 10);

        $user = $this->accounts->register($data['name'], $data['email'], $data['password'], $data['locale'] ?? 'id');

        $this->sendOtp($user, 'verify_email');
        $this->sessions->open($request, $user);

        return response()->json(['user' => self::userRead($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        /** @var array{email: string, password: string} $data */
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:1', 'max:128'],
        ]);
        $key = 'web-login:'.Str::lower($data['email']).'|'.$request->ip();
        $this->limit($request, 'web-login', $key, 10, consume: false);

        $user = $this->activeUserByEmail($data['email']);
        $passwordHash = $user?->getAuthPassword();
        $matches = password_verify($data['password'], $passwordHash ?? self::DUMMY_PASSWORD);
        if ($user === null || $passwordHash === null || ! $matches) {
            RateLimiter::hit($key, 60);
            abort(401, 'Email atau kata sandi salah.');
        }

        RateLimiter::clear($key);
        $this->sessions->open($request, $user);

        return response()->json(['user' => self::userRead($user)]);
    }

    public function logout(Request $request): Response
    {
        $this->sessions->close($request, $this->sessions->activeUser($request));

        return response()->noContent();
    }

    public function requestOtp(Request $request): JsonResponse
    {
        /** @var array{email: string, channel: string, purpose: string} $data */
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'channel' => ['required', 'string', 'in:email,whatsapp,sms'],
            'purpose' => ['required', 'string', 'in:login,reset_password,verify_phone,verify_email'],
        ]);
        $this->assertOtpSupported($data['purpose'], $data['channel']);
        $this->limit($request, 'otp-request', Str::lower($data['email']).'|'.$request->ip(), 5);

        $user = $this->activeUserByEmail($data['email']);
        $expiresAt = $user !== null && ! ($data['purpose'] === 'verify_email' && $user->email_verified_at !== null)
            ? $this->sendOtp($user, $data['purpose'])
            : CarbonImmutable::now()->addMinutes(OneTimeSecrets::OTP_TTL_MINUTES);

        return response()->json(['sent' => true, 'expires_at' => $expiresAt->toIso8601ZuluString()]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        /** @var array{email: string, purpose: string, code: string} $data */
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'purpose' => ['required', 'string', 'in:login,reset_password,verify_phone,verify_email'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);
        $this->assertOtpSupported($data['purpose'], 'email');
        $this->limit($request, 'otp-verify', Str::lower($data['email']).'|'.$request->ip(), 10);

        $user = $this->activeUserByEmail($data['email']);
        abort_unless($user !== null && $this->secrets->consumeOtp($user, $data['purpose'], $data['code']), 401, 'Kode tidak valid atau kedaluwarsa.');

        if ($data['purpose'] === 'verify_email' && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        if ($data['purpose'] === 'login') {
            $this->sessions->open($request, $user);
        }

        return response()->json(['verified' => true, 'purpose' => $data['purpose']]);
    }

    public function requestPasswordReset(Request $request): JsonResponse
    {
        /** @var array{email: string} $data */
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);
        $this->limit($request, 'password-reset', Str::lower($data['email']).'|'.$request->ip(), 5);

        $user = $this->activeUserByEmail($data['email']);
        if ($user !== null) {
            [$token, $expiresAt] = $this->secrets->issueResetToken($user);
            Mail::to($user->email)->send(new OneTimeCodeMail('reset_password_link', $token, $expiresAt->toIso8601ZuluString()));
        }

        return response()->json(['sent' => true]);
    }

    public function confirmPasswordReset(Request $request): JsonResponse
    {
        /** @var array{token: string, password: string} $data */
        $data = $request->validate([
            'token' => ['required', 'string', 'min:16', 'max:128'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ]);
        $this->limit($request, 'password-reset-confirm', (string) $request->ip(), 10);

        $userId = $this->secrets->consumeResetToken($data['token']);
        $user = $userId === null ? null : User::query()->whereKey($userId)->where('status', 'active')->first();
        abort_unless($user instanceof User, 401, 'Token tidak valid atau kedaluwarsa.');

        $this->accounts->resetPassword($user, $data['password']);

        return response()->json(['reset' => true]);
    }

    /** @return array{user_id: string, name: string, email: string, email_verified_at: ?string} */
    public static function userRead(User $user): array
    {
        return [
            'user_id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601ZuluString(),
        ];
    }

    private function sendOtp(User $user, string $purpose): CarbonImmutable
    {
        [$code, $expiresAt] = $this->secrets->issueOtp($user, 'email', $purpose);
        Mail::to($user->email)->send(new OneTimeCodeMail($purpose, $code, $expiresAt->toIso8601ZuluString()));

        return $expiresAt;
    }

    private function activeUserByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->where('status', 'active')->first();
    }

    // Reset kata sandi memakai token email (reset-request); OTP telepon menunggu gateway WhatsApp/SMS.
    private function assertOtpSupported(string $purpose, string $channel): void
    {
        if (! in_array($purpose, self::OTP_PURPOSES, true)) {
            throw ValidationException::withMessages(['purpose' => 'Purpose OTP ini belum tersedia; gunakan login atau verify_email.']);
        }
        if ($channel !== 'email') {
            throw ValidationException::withMessages(['channel' => 'Kanal OTP ini belum tersedia; gunakan email.']);
        }
    }

    private function limit(Request $request, string $scope, string $key, int $max, bool $consume = true): void
    {
        $key = $scope.':'.$key;
        if (RateLimiter::tooManyAttempts($key, $max)) {
            abort(429, 'Terlalu banyak percobaan.', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        if ($consume) {
            RateLimiter::hit($key, 60);
        }
    }
}
