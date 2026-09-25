<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Models;

use App\Modules\MailAgent\Enums\MailOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A processed Gmail message (log + idempotency). No body.
 *
 * @property int $id
 * @property string $gmail_id
 * @property Carbon $received_at
 * @property string|null $sender
 * @property string|null $subject
 * @property string|null $kind
 * @property string|null $parser
 * @property MailOutcome $outcome
 * @property string|null $error
 * @property int|null $candidate_id
 * @property int|null $touchpoint_id
 * @property Carbon|null $created_at
 */
final class MailMessage extends Model
{
    protected $fillable = ['gmail_id', 'received_at', 'sender', 'subject', 'kind', 'parser', 'outcome', 'error', 'candidate_id', 'touchpoint_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'outcome' => MailOutcome::class];
    }
}
