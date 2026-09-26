<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Repositories;

use App\Modules\Recruiting\Contracts\AcquisitionChannelRepository;
use App\Modules\Recruiting\Models\AcquisitionChannel;
use App\Modules\Recruiting\Models\AcquisitionChannelCost;
use App\Modules\Recruiting\Models\ChannelUtmRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentAcquisitionChannelRepository implements AcquisitionChannelRepository
{
    public function all(bool $withInactive): Collection
    {
        return AcquisitionChannel::query()
            ->with(['utmRules' => fn ($q) => $q->orderBy('priority')->orderBy('id'), 'costs' => fn ($q) => $q->orderByDesc('period_start')])
            ->when(! $withInactive, fn (Builder $q) => $q->where('active', true))
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ?AcquisitionChannel
    {
        return AcquisitionChannel::query()->with(['utmRules', 'costs'])->find($id);
    }

    public function findActive(int $id): ?AcquisitionChannel
    {
        return AcquisitionChannel::query()->whereKey($id)->where('active', true)->first();
    }

    public function findActiveByCode(string $code): ?AcquisitionChannel
    {
        return AcquisitionChannel::query()->where('code', $code)->where('active', true)->first();
    }

    public function codeTaken(string $code, ?int $exceptId): bool
    {
        return AcquisitionChannel::query()->where('code', $code)->when($exceptId, fn (Builder $q, int $id) => $q->whereKeyNot($id))->exists();
    }

    public function save(?AcquisitionChannel $channel, array $attributes): AcquisitionChannel
    {
        $channel ??= new AcquisitionChannel;
        $channel->fill($attributes)->save();

        return $this->find($channel->id) ?? $channel;
    }

    public function activeRules(): array
    {
        return array_values(ChannelUtmRule::query()
            ->join('acquisition_channels as c', 'c.id', '=', 'channel_utm_rules.channel_id')
            ->where('c.active', true)
            ->orderBy('channel_utm_rules.id')
            ->get(['channel_utm_rules.id', 'channel_utm_rules.channel_id', 'utm_source', 'utm_medium', 'utm_campaign', 'priority'])
            ->map(static fn (ChannelUtmRule $r): array => [
                'id' => $r->id,
                'channel_id' => $r->channel_id,
                'utm_source' => $r->utm_source,
                'utm_medium' => $r->utm_medium,
                'utm_campaign' => $r->utm_campaign,
                'priority' => $r->priority,
            ])->all());
    }

    public function addRule(AcquisitionChannel $channel, array $attributes): ChannelUtmRule
    {
        return ChannelUtmRule::query()->create(['channel_id' => $channel->id] + $attributes);
    }

    public function findRule(int $id): ?ChannelUtmRule
    {
        return ChannelUtmRule::query()->find($id);
    }

    public function deleteRule(ChannelUtmRule $rule): void
    {
        $rule->delete();
    }

    public function addCost(AcquisitionChannel $channel, array $attributes): AcquisitionChannelCost
    {
        return AcquisitionChannelCost::query()->create(['channel_id' => $channel->id] + $attributes);
    }

    public function findCost(int $id): ?AcquisitionChannelCost
    {
        return AcquisitionChannelCost::query()->find($id);
    }

    public function deleteCost(AcquisitionChannelCost $cost): void
    {
        $cost->delete();
    }
}
