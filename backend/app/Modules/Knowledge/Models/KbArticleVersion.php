<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $article_id
 * @property int $version
 * @property string $title
 * @property string $body_md
 * @property int|null $edited_by
 * @property Carbon|null $created_at
 */
final class KbArticleVersion extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'kb_article_versions';

    protected $fillable = ['article_id', 'version', 'title', 'body_md', 'edited_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
