<?php

declare(strict_types=1);

namespace App\Modules\Channels\Services;

use App\Modules\Channels\DTO\DemoSeed;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Support\ContactNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Sender of a simulated event: an existing candidate (their contacts, so the event matches them), a contact typed
 * by the superadmin, or a synthetic stranger (lands in the inbox). Synthetic data only.
 */
final readonly class DemoSeedFactory
{
    private const array NAMES = ['Олена Демо', 'Andrii Test', 'Марія Приклад', 'Taras Sample'];

    private const array TEXTS = [
        'Добрий день! Бачив вакансію, ще актуально?',
        'Hello! Is the position still open? I can talk after 18:00.',
        'Дякую за дзвінок, надсилаю резюме сюди.',
    ];

    public function __construct(private ContactNormalizer $normalizer) {}

    public function make(?Candidate $candidate, ?string $contact, ?string $text): DemoSeed
    {
        $number = random_int(1_000_000, 9_999_999);
        $phone = null;
        $telegram = null;
        $name = self::NAMES[array_rand(self::NAMES)];
        if ($candidate !== null) {
            $phone = $candidate->phone;
            $telegram = $candidate->telegram_username;
            $name = $candidate->full_name;
        } elseif ($contact !== null) {
            $keys = $this->normalizer->guess($contact);
            $phone = $keys->phone;
            $telegram = $keys->telegram;
        } else {
            $phone = '+38050'.$number;
            $telegram = 'demo_candidate_'.$number;
        }

        return new DemoSeed(
            phone: $phone,
            telegramUsername: $telegram,
            senderName: $name,
            text: $text ?? self::TEXTS[array_rand(self::TEXTS)],
            at: Carbon::now(),
            uid: Str::lower(Str::random(16)),
            numericId: random_int(100_000_000, 999_999_999),
        );
    }
}
