<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Support;

use SensitiveParameter;

/**
 * The SAME OAuth client as login (config services.google, Vercel env), with its own redirect URI for the
 * consent flow: https://sinhrm.vercel.app/api/google/connect/callback (must be registered in Google Cloud).
 */
final readonly class GoogleOAuthConfig
{
    public const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(
        public ?string $clientId,
        #[SensitiveParameter] public ?string $clientSecret,
        public string $redirectUri,
    ) {}

    /** @param  array<string, mixed>  $google  config('services.google') */
    public static function fromConfig(array $google): self
    {
        $str = static fn (string $key): ?string => isset($google[$key]) && is_string($google[$key]) && $google[$key] !== ''
            ? $google[$key] : null;

        return new self(
            $str('client_id'),
            $str('client_secret'),
            $str('connect_redirect') ?? 'https://sinhrm.vercel.app/api/google/connect/callback',
        );
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== null && $this->clientSecret !== null;
    }
}
