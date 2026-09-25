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

    /** Always requested: the connected account's e-mail comes from the id_token. */
    public const array IDENTITY_SCOPES = ['openid', 'email'];

    public function integrationKey(): string
    {
        return 'google_'.$this->value;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return match ($this) {
            // Read-only on purpose: the mail agent never sends mail (scope minimisation).
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
