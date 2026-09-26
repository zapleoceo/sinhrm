<?php

declare(strict_types=1);

namespace App\Modules\Desk\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reply in the case thread: public (the employee sees it) or an internal note (HR only).
 *
 * @property int $id
 * @property int $case_id
 * @property int|null $author_id
 * @property string $body
 * @property bool $internal
 * @property int|null $article_id
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 */
final class DeskComment extends Model
{
    protected $table = 'desk_comments';

    protected $fillable = ['case_id', 'author_id', 'body', 'internal', 'article_id'];

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['internal' => 'boolean', 'article_id' => 'integer'];
    }
}
