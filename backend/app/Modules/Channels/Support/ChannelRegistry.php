<?php

declare(strict_types=1);

namespace App\Modules\Channels\Support;

use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Contracts\MessageSender;
use App\Modules\Recruiting\Enums\Channel;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** All channel adapters by integration key (tag ChannelsServiceProvider::ADAPTERS_TAG). */
final class ChannelRegistry
{
    /** @var array<string, ChannelAdapter> */
    private array $adapters = [];

    /** @param  iterable<ChannelAdapter>  $adapters */
    public function __construct(iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            if (isset($this->adapters[$adapter->key()])) {
                throw new InvalidArgumentException("Duplicate channel adapter: {$adapter->key()}");
            }
            $this->adapters[$adapter->key()] = $adapter;
        }
    }

    /** @return list<ChannelAdapter> */
    public function all(): array
    {
        return array_values($this->adapters);
    }

    /** @throws NotFoundHttpException unknown key → 404 */
    public function get(string $key): ChannelAdapter
    {
        return $this->adapters[$key] ?? throw new NotFoundHttpException('Unknown channel.');
    }

    /** The adapter that sends messages of a timeline channel (telegram → telegram_business, …), if any. */
    public function senderFor(Channel $channel): ?ChannelAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->channel() === $channel && $adapter instanceof MessageSender) {
                return $adapter;
            }
        }

        return null;
    }

    /** @return list<ChannelAdapter> telephony adapters (channel "call") */
    public function telephony(): array
    {
        return array_values(array_filter($this->adapters, static fn (ChannelAdapter $a): bool => $a->channel() === Channel::Call));
    }
}
