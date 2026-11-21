<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Platform\Http\StrictValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as OAuthUser;
use Symfony\Component\HttpFoundation\RedirectResponse as ProviderRedirect;
use Throwable;

// Login sosial storefront saja; admin tidak punya jalur sosial (Doc 12 §3.1).
final class SocialAuthController
{
    private const LINK_INTENT = 'social_link_intent';

    public function __construct(
        private readonly WebSession $sessions,
        private readonly SocialAccountResolver $resolver,
    ) {}

    // Menautkan ke akun yang sedang terbuka hanya bila diminta eksplisit (intent=link);
    // login sosial biasa di browser bersama tidak menambah kredensial ke akun orang lain.
    public function redirect(Request $request, string $provider): ProviderRedirect
    {
        /** @var array{intent?: string} $data */
        $data = StrictValidation::validate($request, ['intent' => 'sometimes|string|in:link']);
        $linking = ($data['intent'] ?? null) === 'link' && $this->sessions->user($request) !== null;
        $request->session()->put(self::LINK_INTENT, $linking ? $provider : null);

        return Socialite::driver($provider)->redirect();
    }

    // Callback membawa parameter tambahan dari provider (scope, authuser, ...), jadi tidak divalidasi ketat.
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:512'],
            'state' => ['required', 'string', 'max:512'],
        ]);
        $linking = $request->session()->pull(self::LINK_INTENT) === $provider;

        try {
            $social = Socialite::driver($provider)->user();
        } catch (InvalidStateException) {
            abort(401, 'State OAuth tidak valid.');
        } catch (Throwable $exception) {
            report($exception);
            abort(401, 'Provider menolak otorisasi.');
        }

        $raw = $social instanceof OAuthUser ? $social->getRaw() : [];
        $current = $this->sessions->user($request);
        $user = $this->resolver->resolve(
            $provider,
            (string) $social->getId(),
            is_string($social->getEmail()) && $social->getEmail() !== '' ? $social->getEmail() : null,
            $provider === 'google' && ($raw['email_verified'] ?? false) === true,
            $social->getName() ?? $social->getNickname(),
            $linking ? $current : null,
        );

        if (! $user->is($current)) {
            if ($current !== null) {
                $this->sessions->close($request, $current);
            }
            $this->sessions->open($request, $user);
        }

        return redirect()->to(url('/account'));
    }
}
