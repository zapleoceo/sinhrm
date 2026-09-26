<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Spend on a channel for a period (inclusive dates). Reports prorate it by the days that overlap the report range.
 *
 * @property int $id
 * @property int $channel_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $amount decimal
 * @property string $currency
 * @property string|null $note
 */
final class AcquisitionChannelCost extends Model
{
    protected $fillable = ['channel_id', 'period_start', 'period_end', 'amount', 'currency', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date'];
    }
}
