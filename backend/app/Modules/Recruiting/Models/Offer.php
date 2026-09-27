<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use App\Modules\Recruiting\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Job offer of an application (one per application). The salary is sensitive: only whoever may work the vacancy
 * (recruiting writers in scope, the hiring manager) sees the offer at all.
 *
 * @property int $id
 * @property int $application_id
 * @property int|null $template_id
 * @property string $position
 * @property string $salary
 * @property Carbon|null $start_date
 * @property string|null $conditions
 * @property string $content_md
 * @property OfferStatus $status
 * @property Carbon|null $sent_at
 * @property Carbon|null $decided_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read Application $application
 */
final class Offer extends Model
{
    protected $fillable = [
        'application_id', 'template_id', 'position', 'salary', 'start_date', 'conditions', 'content_md', 'status',
        'sent_at', 'decided_at', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'start_date' => 'date',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
