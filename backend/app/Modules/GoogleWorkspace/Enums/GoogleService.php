<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Enums;

/**
 * A Google product connected through the OAuth consent flow (separate from login). Each maps to one integration
 * row (status, non-secret settings) and its vault secrets (refresh_token, access_token).
 */
enum GoogleService: string
{
    case Gmail = 'gmail';
    case Calendar = 'calendar';
    case Sheets = 'sheets';

    /** Sending mail through the connected mailbox (candidate card, workflows). Optional: without it Gmail still reads. */
    public const string GMAIL_SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    /** Always requested: the connected account's e-mail comes from the id_token. */
    public const array IDENTITY_SCOPES = ['openid', 'email'];

    public function integrationKey(): string
    {
        return 'google_'.$this->value;
    }

    /**
     * Everything requested on the consent screen.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::Gmail => [...$this->requiredScopes(), self::GMAIL_SEND_SCOPE],
            default => $this->requiredScopes(),
        };
    }

    /**
     * Scopes without which the service is not connected at all. gmail.send is not here: a mailbox connected before
     * sending existed (or with the send box unticked) keeps reading, only sending asks for a reconnect.
     *
     * @return list<string>
     */
    public function requiredScopes(): array
    {
        return match ($this) {
            self::Gmail => ['https://www.googleapis.com/auth/gmail.readonly'],
            self::Calendar => ['https://www.googleapis.com/auth/calendar.events'],
            self::Sheets => ['https://www.googleapis.com/auth/spreadsheets.readonly'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /**
     * "gmail,calendar" → services; unknown names are ignored, duplicates removed.
     *
     * @return list<self>
     */
    public static function parseList(string $csv): array
    {
        $services = [];
        foreach (explode(',', $csv) as $name) {
            $service = self::tryFrom(trim($name));
            if ($service !== null && ! in_array($service, $services, true)) {
                $services[] = $service;
            }
        }

        return $services;
    }
}
