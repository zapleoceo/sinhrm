<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $subject_type candidate|employee
 * @property int $subject_id
 * @property string $action export|erase
 * @property string $trigger manual|retention
 * @property string|null $reason
 * @property int|null $actor_id
 * @property array<string, int>|null $counts
 * @property Carbon|null $created_at
 */
final class PrivacyRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['subject_type', 'subject_id', 'action', 'trigger', 'reason', 'actor_id', 'counts'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['counts' => 'array', 'subject_id' => 'integer'];
    }
}
