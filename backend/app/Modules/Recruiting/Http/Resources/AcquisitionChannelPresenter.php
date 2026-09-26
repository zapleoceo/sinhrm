<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Resources;

use App\Modules\Recruiting\Models\AcquisitionChannel;
use App\Modules\Recruiting\Models\AcquisitionChannelCost;
use App\Modules\Recruiting\Models\ChannelUtmRule;

/** Channel as JSON; UTM rules and costs only for the dictionary managers (costs are internal numbers). */
final class AcquisitionChannelPresenter
{
    /** @return array<string, mixed> */
    public static function present(AcquisitionChannel $c, bool $manage): array
    {
        $out = ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'type' => $c->type->value, 'active' => $c->active];
        if ($manage) {
            $out['utm_rules'] = $c->utmRules->map(static fn (ChannelUtmRule $r): array => [
                'id' => $r->id, 'utm_source' => $r->utm_source, 'utm_medium' => $r->utm_medium,
                'utm_campaign' => $r->utm_campaign, 'priority' => $r->priority,
            ])->values()->all();
            $out['costs'] = $c->costs->map(static fn (AcquisitionChannelCost $k): array => [
                'id' => $k->id, 'period_start' => $k->period_start->toDateString(), 'period_end' => $k->period_end->toDateString(),
                'amount' => (float) $k->amount, 'currency' => $k->currency, 'note' => $k->note,
            ])->values()->all();
        }

        return $out;
    }
}
