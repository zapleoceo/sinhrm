<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Http\Resources;

use App\Modules\Knowledge\Models\KbArticle;
use App\Modules\Knowledge\Models\KbCategory;

/** JSON of articles: the list without bodies; the detail with sanitized html (and markdown for editors). */
final class ArticlePresenter
{
    /** @return array<string, mixed> */
    public static function present(KbArticle $a, bool $editor, bool $detailed = false, ?bool $myVote = null): array
    {
        $out = [
            'id' => $a->id,
            'title' => $a->title,
            'category' => $a->category === null ? null : ['id' => $a->category->id, 'name' => $a->category->name, 'emoji' => $a->category->emoji],
            'tags' => $a->tags,
            'status' => $a->status->value,
            'version' => $a->version,
            'votes' => ['helpful' => (int) $a->helpful_count, 'not_helpful' => (int) $a->not_helpful_count],
            'published_at' => $a->published_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
            'can_edit' => $editor,
        ];
        if ($editor) {
            $out['audience'] = $a->audience;
        }
        if (! $detailed) {
            return $out;
        }

        return $out + ['html' => $a->body_html, 'body_md' => $editor ? $a->body_md : null, 'my_vote' => $myVote];
    }

    /** @return array<string, mixed> */
    public static function category(KbCategory $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'emoji' => $c->emoji, 'position' => $c->position];
    }
}
