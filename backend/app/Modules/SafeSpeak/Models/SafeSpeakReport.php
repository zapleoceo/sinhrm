<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Models;

use App\Modules\SafeSpeak\Enums\ReportCategory;
use App\Modules\SafeSpeak\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An anonymous report. No identity of the reporter and no timestamps (date only) — see the migration.
 *
 * @property int $id
 * @property string $access_code_hash
 * @property ReportCategory $category
 * @property string $subject
 * @property ReportStatus $status
 * @property Carbon $created_on
 * @property Carbon $updated_on
 * @property int|null $messages_count
 * @property-read Collection<int, SafeSpeakMessage> $messages
 */
final class SafeSpeakReport extends Model
{
    public $timestamps = false;

    protected $table = 'safe_speak_reports';

    protected $fillable = ['access_code_hash', 'category', 'subject', 'status', 'created_on', 'updated_on'];

    /** @var list<string> */
    protected $hidden = ['access_code_hash'];

    /** @return HasMany<SafeSpeakMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SafeSpeakMessage::class, 'report_id')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => ReportCategory::class,
            'status' => ReportStatus::class,
            'created_on' => 'date',
            'updated_on' => 'date',
        ];
    }
}
