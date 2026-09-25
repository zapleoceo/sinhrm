<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Support;

use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;

/**
 * Rule-based hint for the unknown-senders queue (no AI): known job-board domains → job_board + parser;
 * typical robot addresses → newsletter. Only a suggestion: a superadmin confirms it with one click.
 */
final class SenderSuggester
{
    private const array BOARDS = [
        'work.ua' => ParserKey::WorkUa,
        'robota.ua' => ParserKey::RobotaUa,
        'rabota.ua' => ParserKey::RobotaUa,
        'djinni.co' => ParserKey::Djinni,
    ];

    /** @return array{0: SenderKind|null, 1: ParserKey|null} */
    public static function suggest(string $email): array
    {
        $domain = SenderPattern::domainOf($email);
        foreach (self::BOARDS as $board => $parser) {
            if ($domain === $board || str_ends_with($domain, '.'.$board)) {
                return [SenderKind::JobBoard, $parser];
            }
        }
        $local = mb_strtolower(substr($email, 0, (int) strrpos($email, '@')));
        if (preg_match('/(no-?reply|newsletter|news|digest|marketing|promo|mailer)/', $local) === 1) {
            return [SenderKind::Newsletter, null];
        }

        return [null, null];
    }
}
