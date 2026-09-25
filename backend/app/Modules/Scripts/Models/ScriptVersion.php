<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Models;

use App\Models\User;
use App\Modules\Scripts\DTO\ScriptContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A version of a script. published_at null = the draft (editable); published = immutable forever: evaluations
 * keep pointing to the exact version they were made with.
 *
 * @property int $id
 * @property int $script_id
 * @property int $version
 * @property Carbon|null $published_at
 * @property int|null $author_id
 * @property list<array<string, mixed>> $steps
 * @property list<array<string, mixed>> $objections
 * @property list<array<string, mixed>> $templates
 * @property list<array<string, mixed>> $followups
 * @property array<string, mixed> $next_step_patterns
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Script $script
 * @property-read User|null $author
 */
final class ScriptVersion extends Model
{
    protected $fillable = [
        'script_id', 'version', 'published_at', 'author_id', 'steps', 'objections', 'templates', 'followups', 'next_step_patterns',
    ];

    protected static function booted(): void
    {
        // Immutability guard below the service layer: a published version is never rewritten.
        self::updating(static function (self $version): void {
            if ($version->getOriginal('published_at') !== null) {
                throw new LogicException('A published script version is immutable.');
            }
        });
    }

    /** @return BelongsTo<Script, $this> */
    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    public function content(): ScriptContent
    {
        return ScriptContent::fromArray([
            'steps' => $this->steps,
            'objections' => $this->objections,
            'templates' => $this->templates,
            'followups' => $this->followups,
            'next_step_patterns' => $this->next_step_patterns,
        ]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'steps' => 'array',
            'objections' => 'array',
            'templates' => 'array',
            'followups' => 'array',
            'next_step_patterns' => 'array',
        ];
    }
}
