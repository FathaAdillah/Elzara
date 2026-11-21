<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Modules\Identity\Infrastructure\Mail\OneTimeCodeMail;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Platform\Http\StrictValidation;
use App\Platform\Http\WireTime;
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

    // bcrypt mengabaikan byte setelah ke-72; kata sandi lebih panjang ditolak, bukan dipotong diam-diam.
    private const PASSWORD_MAX_BYTES = 72;

    private const DUMMY_PASSWORD = '$2y$12$4Oe8Yfb9YvX1t0Yb1o5Ywu1NnH6eQ3m5yJc3o5n2kJ8l9q2r0s1tC';

    public function __construct(
        private readonly WebSession $sessions,
        private readonly OneTimeSecrets $secrets,
        private readonly StorefrontAccounts $accounts,
    ) {}

    public function register(Request $request): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, locale?: string} $data */
        $data = StrictValidation::validate($request, [
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|string|email|max:254',
            'password' => 'required|string|min:8|max:72',
            'locale' => 'sometimes|string|in:id,en',
        ]);
        $this->assertPasswordFits($data['password']);
        $this->limit('register', (string) $request->ip(), 10);

        $user = $this->accounts->register($data['name'], $data['email'], $data['password'], $data['locale'] ?? 'id');

        $this->sendOtp($user, 'verify_email');
        $this->sessions->open($request, $user);

        return response()->json(['user' => self::userRead($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        /** @var array{email: string, password: string} $data */
        $data = StrictValidation::validate($request, [
            'email' => 'required|string|email|max:254',
            'password' => 'required|string|min:1|max:128',
        ]);
        $key = $this->limit('web-login', Str::lower($data['email']).'|'.$request->ip(), 10, consume: false);

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
        $data = StrictValidation::validate($request, [
            'email' => 'required|string|email|max:254',
            'channel' => 'required|string|in:email,whatsapp,sms',
            'purpose' => 'required|string|in:login,reset_password,verify_phone,verify_email',
        ]);
        $this->assertOtpSupported($data['purpose'], $data['channel']);
        $this->limit('otp-request', Str::lower($data['email']).'|'.$request->ip(), 5);

        $user = $this->activeUserByEmail($data['email']);
        $expiresAt = $user !== null && ! ($data['purpose'] === 'verify_email' && $user->email_verified_at !== null)
            ? $this->sendOtp($user, $data['purpose'])
            : $this->secrets->simulateIssue(OneTimeSecrets::OTP_TTL_MINUTES);

        return response()->json(['sent' => true, 'expires_at' => WireTime::format($expiresAt)]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        /** @var array{email: string, purpose: string, code: string} $data */
        $data = StrictValidation::validate($request, [
            'email' => 'required|string|email|max:254',
            'purpose' => 'required|string|in:login,reset_password,verify_phone,verify_email',
            'code' => 'required|string|regex:/^[0-9]{6}$/',
        ]);
        $this->assertOtpSupported($data['purpose'], 'email');
        $this->limit('otp-verify', Str::lower($data['email']).'|'.$request->ip(), 10);

        $user = $this->activeUserByEmail($data['email']);
        abort_unless($user !== null && $this->secrets->consumeOtp($user, $data['purpose'], $data['code']), 401, 'Kode tidak valid atau kedaluwarsa.');

        // Kode email membuktikan kepemilikan kotak surat. Bila bukti itu datang dari luar sesi
        // pendaftar, kata sandi dan sesi yang dibuat sebelum verifikasi dicabut.
        if ($user->email_verified_at === null) {
            $this->accounts->markEmailVerified($user, revokeExisting: ! $user->is($this->sessions->user($request)));
        }
        if ($data['purpose'] === 'login') {
            $this->sessions->open($request, $user);
        }

        return response()->json(['verified' => true, 'purpose' => $data['purpose']]);
    }

    public function requestPasswordReset(Request $request): JsonResponse
    {
        /** @var array{email: string} $data */
        $data = StrictValidation::validate($request, ['email' => 'required|string|email|max:254']);
        $this->limit('password-reset', Str::lower($data['email']).'|'.$request->ip(), 5);

        $user = $this->activeUserByEmail($data['email']);
        if ($user !== null) {
            [$token, $expiresAt] = $this->secrets->issueResetToken($user);
            $this->mailAfterResponse($user, new OneTimeCodeMail('reset_password_link', $token, WireTime::format($expiresAt)));
        } else {
            $this->secrets->simulateIssue(OneTimeSecrets::RESET_TTL_MINUTES);
        }

        return response()->json(['sent' => true]);
    }

    public function confirmPasswordReset(Request $request): JsonResponse
    {
        /** @var array{token: string, password: string} $data */
        $data = StrictValidation::validate($request, [
            'token' => 'required|string|min:16|max:128',
            'password' => 'required|string|min:8|max:72',
        ]);
        $this->assertPasswordFits($data['password']);
        $this->limit('password-reset-confirm', (string) $request->ip(), 10);

        $userId = $this->secrets->consumeResetToken($data['token']);
        $user = $userId === null ? null : User::query()->whereKey($userId)->where('status', 'active')->first();
        abort_unless($user instanceof User, 401, 'Token tidak valid atau kedaluwarsa.');

        $this->accounts->resetPassword($user, $data['password']);

        return response()->json(['reset' => true]);
    }

    /** @return array{user_id: string, name: string, email: string, email_verified_at: ?string, timezone: string} */
    public static function userRead(User $user): array
    {
        return [
            'user_id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at === null ? null : WireTime::format($user->email_verified_at),
            'timezone' => $user->timezone,
        ];
    }

    private function sendOtp(User $user, string $purpose): CarbonImmutable
    {
        [$code, $expiresAt] = $this->secrets->issueOtp($user, 'email', $purpose);
        $this->mailAfterResponse($user, new OneTimeCodeMail($purpose, $code, WireTime::format($expiresAt)));

        return $expiresAt;
    }

    // Kirim setelah respons agar waktu respons tidak membedakan email terdaftar dari yang tidak;
    // tetap di proses yang sama supaya rahasia mentah tidak pernah ditulis ke antrean.
    private function mailAfterResponse(User $user, OneTimeCodeMail $mail): void
    {
        $email = $user->email;
        dispatch(fn () => Mail::to($email)->send($mail))->afterResponse();
    }

    private function activeUserByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->where('status', 'active')->first();
    }

    private function assertPasswordFits(string $password): void
    {
        if (strlen($password) > self::PASSWORD_MAX_BYTES) {
            throw ValidationException::withMessages(['password' => 'Kata sandi maksimal 72 byte.']);
        }
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

    private function limit(string $scope, string $subject, int $max, bool $consume = true): string
    {
        $key = 'web-auth:'.$scope.':'.$subject;
        if (RateLimiter::tooManyAttempts($key, $max)) {
            abort(429, 'Terlalu banyak percobaan.', ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        if ($consume) {
            RateLimiter::hit($key, 60);
        }

        return $key;
    }
}
