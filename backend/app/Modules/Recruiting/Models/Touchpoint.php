<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Models;

use App\Models\User;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Any contact with a candidate (call, messenger, e-mail, note, meeting) or a system event.
 * via_product = made from SinHRM; false = captured from outside (integration). candidate_id null = unmatched (inbox).
 *
 * @property int $id
 * @property int|null $candidate_id
 * @property int|null $application_id
 * @property int|null $branch_id
 * @property int|null $stage_change_id
 * @property Channel $channel
 * @property Direction $direction
 * @property int|null $author_id
 * @property Carbon $occurred_at
 * @property string|null $body
 * @property array<string, mixed>|null $meta
 * @property string|null $external_id
 * @property bool $via_product
 * @property string|null $integration_key
 * @property Carbon|null $created_at
 * @property-read Candidate|null $candidate
 * @property-read Application|null $application
 * @property-read User|null $author
 */
final class Touchpoint extends Model
{
    protected $fillable = [
        'candidate_id', 'application_id', 'branch_id', 'stage_change_id', 'channel', 'direction', 'author_id',
        'occurred_at', 'body', 'meta', 'external_id', 'via_product', 'integration_key',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['direction' => 'out', 'via_product' => false];

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Contact of the sender as the source gave it (phone / e-mail / @username), if any. */
    public function contact(): ?string
    {
        $contact = $this->meta['contact'] ?? null;

        return is_string($contact) && $contact !== '' ? $contact : null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'direction' => Direction::class,
            'occurred_at' => 'datetime',
            'meta' => 'array',
            'via_product' => 'boolean',
        ];
    }
}
