<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Contracts;

use App\Modules\Recruiting\Models\AcquisitionChannel;
use App\Modules\Recruiting\Models\AcquisitionChannelCost;
use App\Modules\Recruiting\Models\ChannelUtmRule;
use Illuminate\Database\Eloquent\Collection;

/** The acquisition channels dictionary with UTM rules and costs (tz3). */
interface AcquisitionChannelRepository
{
    /** @return Collection<int, AcquisitionChannel> ordered by name, with rules and costs */
    public function all(bool $withInactive): Collection;

    public function find(int $id): ?AcquisitionChannel;

    public function findActive(int $id): ?AcquisitionChannel;

    public function findActiveByCode(string $code): ?AcquisitionChannel;

    public function codeTaken(string $code, ?int $exceptId): bool;

    /** @param  array<string, mixed>  $attributes */
    public function save(?AcquisitionChannel $channel, array $attributes): AcquisitionChannel;

    /**
     * UTM rules of active channels, as plain rows for UtmMatcher.
     *
     * @return list<array{id: int, channel_id: int, utm_source: string|null, utm_medium: string|null, utm_campaign: string|null, priority: int}>
     */
    public function activeRules(): array;

    /** @param  array<string, mixed>  $attributes */
    public function addRule(AcquisitionChannel $channel, array $attributes): ChannelUtmRule;

    public function findRule(int $id): ?ChannelUtmRule;

    public function deleteRule(ChannelUtmRule $rule): void;

    /** @param  array<string, mixed>  $attributes */
    public function addCost(AcquisitionChannel $channel, array $attributes): AcquisitionChannelCost;

    public function findCost(int $id): ?AcquisitionChannelCost;

    public function deleteCost(AcquisitionChannelCost $cost): void;
}
