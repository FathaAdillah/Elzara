<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

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
    public const DRIVERS = ['google' => 'google', 'x' => 'twitter'];

    public function __construct(
        private readonly WebSession $sessions,
        private readonly SocialAccountResolver $resolver,
    ) {}

    public function redirect(string $provider): ProviderRedirect
    {
        return Socialite::driver(self::DRIVERS[$provider])->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:512'],
            'state' => ['required', 'string', 'max:512'],
        ]);

        try {
            $social = Socialite::driver(self::DRIVERS[$provider])->user();
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
            $current,
        );

        if ($current === null) {
            $this->sessions->open($request, $user);
        }

        return redirect()->to(url('/account'));
    }
}
