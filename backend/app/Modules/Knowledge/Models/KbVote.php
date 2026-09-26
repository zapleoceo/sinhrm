<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "Was this helpful?" — one per user and article.
 *
 * @property int $id
 * @property int $article_id
 * @property int $user_id
 * @property bool $helpful
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class KbVote extends Model
{
    protected $table = 'kb_votes';

    protected $fillable = ['article_id', 'user_id', 'helpful'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['helpful' => 'boolean'];
    }
}
