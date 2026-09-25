<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a candidate's route on a vacancy. from_stage_id null = the application was created.
 *
 * @property int $id
 * @property int $application_id
 * @property int|null $from_stage_id
 * @property int $to_stage_id
 * @property int|null $by_user_id
 * @property string|null $reason
 * @property Carbon $at
 * @property-read Application $application
 * @property-read PipelineStage|null $fromStage
 * @property-read PipelineStage $toStage
 * @property-read User|null $byUser
 */
final class StageChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['application_id', 'from_stage_id', 'to_stage_id', 'by_user_id', 'reason', 'at'];

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'from_stage_id');
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'to_stage_id');
    }

    /** @return BelongsTo<User, $this> */
    public function byUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
