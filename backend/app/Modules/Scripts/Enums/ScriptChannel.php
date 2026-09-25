<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Enums;

use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;

/** What a script is for: a phone call or messenger correspondence. */
enum ScriptChannel: string
{
    case Call = 'call';
    case Chat = 'chat';

    /** Messenger channels whose outbound messages are checked against the chat script. */
    public const array CHAT_CHANNELS = [Channel::Telegram, Channel::Whatsapp, Channel::Viber];

    /** Outbound chat messages up to this length (greetings, a bare link) are not evaluated. */
    public const int MIN_CHAT_LENGTH = 200;

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** @return list<string> */
    public static function chatChannelValues(): array
    {
        return array_map(static fn (Channel $c): string => $c->value, self::CHAT_CHANNELS);
    }

    /**
     * Which script evaluates a touch: a call with a transcript (body), or an outbound messenger message longer than
     * MIN_CHAT_LENGTH characters. null = the touch is not evaluated.
     */
    public static function forTouch(Channel $channel, Direction $direction, ?string $body): ?self
    {
        $text = trim((string) $body);
        if ($text === '') {
            return null;
        }
        if ($channel === Channel::Call) {
            return self::Call;
        }
        if ($direction === Direction::Out && in_array($channel, self::CHAT_CHANNELS, true) && mb_strlen($text) > self::MIN_CHAT_LENGTH) {
            return self::Chat;
        }

        return null;
    }
}
