<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * utm_source / utm_medium / utm_campaign → channel. Null field = "any"; values are stored lowercase.
 *
 * @property int $id
 * @property int $channel_id
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property int $priority lower wins among equally specific rules
 * @property-read AcquisitionChannel $channel
 */
final class ChannelUtmRule extends Model
{
    protected $fillable = ['channel_id', 'utm_source', 'utm_medium', 'utm_campaign', 'priority'];

    /** @return BelongsTo<AcquisitionChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(AcquisitionChannel::class, 'channel_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['priority' => 'integer'];
    }
}
