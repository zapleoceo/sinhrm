<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Models;

use App\Modules\Knowledge\Enums\ArticleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A knowledge base article. body_html is rendered from body_md on save (sanitized).
 *
 * @property int $id
 * @property int|null $category_id
 * @property string $title
 * @property string $body_md
 * @property string $body_html
 * @property list<string> $tags
 * @property array{type: string, ids?: list<int>, roles?: list<string>} $audience
 * @property ArticleStatus $status
 * @property int $version
 * @property int|null $author_id
 * @property int|null $updated_by
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $helpful_count
 * @property int|null $not_helpful_count
 * @property-read KbCategory|null $category
 */
final class KbArticle extends Model
{
    protected $table = 'kb_articles';

    protected $fillable = ['category_id', 'title', 'body_md', 'body_html', 'tags', 'audience', 'status', 'version', 'author_id', 'updated_by', 'published_at'];

    /** @return BelongsTo<KbCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'category_id');
    }

    /** @return HasMany<KbVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(KbVote::class, 'article_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'audience' => 'array',
            'status' => ArticleStatus::class,
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
