<?php

declare(strict_types=1);

namespace App\Modules\Perform\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One progress update of an objective (history).
 *
 * @property int $id
 * @property int $objective_id
 * @property int|null $author_id
 * @property int $progress_before
 * @property int $progress_after
 * @property list<array{id: string, current: float|int}> $key_results
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 */
final class ObjectiveCheckin extends Model
{
    protected $fillable = ['objective_id', 'author_id', 'progress_before', 'progress_after', 'key_results', 'comment'];

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['key_results' => 'array', 'progress_before' => 'integer', 'progress_after' => 'integer'];
    }
}
