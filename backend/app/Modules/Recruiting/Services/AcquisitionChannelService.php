<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Modules\Recruiting\Contracts\AcquisitionChannelRepository;
use App\Modules\Recruiting\Enums\CandidateSource;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Models\AcquisitionChannel;
use App\Modules\Recruiting\Models\AcquisitionChannelCost;
use App\Modules\Recruiting\Models\ChannelUtmRule;
use App\Modules\Recruiting\Support\UtmMatcher;
use Illuminate\Database\Eloquent\Collection;

/**
 * Acquisition channels (tz3): the dictionary, UTM rules, costs, and the channel of a new candidate.
 *
 * Channel resolution precedence (resolve()):
 *   1. an explicit channel_id (a recruiter picked it) — must be active, else 422 channel_inactive;
 *   2. a UTM rule match (UtmMatcher: most specific, then priority, then oldest);
 *   3. an active channel whose code equals the legacy source value (work_ua, linkedin, …);
 *   4. none (null) — e.g. source "manual" without UTM.
 */
final readonly class AcquisitionChannelService
{
    public function __construct(private AcquisitionChannelRepository $channels) {}

    /**
     * @param  array<string, mixed>|null  $utm
     *
     * @throws RecruitingException
     */
    public function resolve(?int $explicitId, ?CandidateSource $source, ?array $utm): ?int
    {
        if ($explicitId !== null) {
            return ($this->channels->findActive($explicitId) ?? throw RecruitingException::channelInactive())->id;
        }
        $rule = UtmMatcher::match($this->channels->activeRules(), $utm);
        if ($rule !== null) {
            return $rule['channel_id'];
        }

        return $source === null ? null : $this->channels->findActiveByCode($source->value)?->id;
    }

    /** @return Collection<int, AcquisitionChannel> */
    public function list(bool $withInactive): Collection
    {
        return $this->channels->all($withInactive);
    }

    public function find(int $id): AcquisitionChannel
    {
        return $this->channels->find($id) ?? abort(404);
    }

    /**
     * @param  array<string, mixed>  $attributes  code (lowercased), name, type, active
     *
     * @throws RecruitingException
     */
    public function save(?AcquisitionChannel $channel, array $attributes): AcquisitionChannel
    {
        if (isset($attributes['code'])) {
            $attributes['code'] = mb_strtolower((string) $attributes['code']);
            if ($this->channels->codeTaken($attributes['code'], $channel?->id)) {
                throw RecruitingException::channelCodeTaken();
            }
        }

        return $this->channels->save($channel, $attributes);
    }

    /**
     * @param  array{utm_source?: string|null, utm_medium?: string|null, utm_campaign?: string|null, priority?: int}  $attributes
     *
     * @throws RecruitingException
     */
    public function addRule(AcquisitionChannel $channel, array $attributes): ChannelUtmRule
    {
        $clean = ['priority' => $attributes['priority'] ?? 100];
        foreach (UtmMatcher::KEYS as $key) {
            $v = isset($attributes[$key]) ? mb_strtolower(trim((string) $attributes[$key])) : '';
            $clean[$key] = $v === '' ? null : $v;
        }
        if ($clean['utm_source'] === null && $clean['utm_medium'] === null && $clean['utm_campaign'] === null) {
            throw RecruitingException::emptyUtmRule();
        }

        return $this->channels->addRule($channel, $clean);
    }

    public function deleteRule(int $id): void
    {
        $this->channels->deleteRule($this->channels->findRule($id) ?? abort(404));
    }

    /** @param  array<string, mixed>  $attributes  period_start, period_end, amount, currency?, note? */
    public function addCost(AcquisitionChannel $channel, array $attributes): AcquisitionChannelCost
    {
        return $this->channels->addCost($channel, $attributes);
    }

    public function deleteCost(int $id): void
    {
        $this->channels->deleteCost($this->channels->findCost($id) ?? abort(404));
    }

    /**
     * "Which channel would this UTM give?" for the rules editor.
     *
     * @param  array<string, mixed>  $utm
     * @return array{channel_id: int|null, rule_id: int|null}
     */
    public function preview(array $utm): array
    {
        $rule = UtmMatcher::match($this->channels->activeRules(), $utm);

        return ['channel_id' => $rule['channel_id'] ?? null, 'rule_id' => $rule['id'] ?? null];
    }
}
