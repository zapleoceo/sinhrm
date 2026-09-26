<?php

declare(strict_types=1);

namespace App\Modules\Desk\Http\Resources;

use App\Modules\Desk\Models\DeskAttachment;
use App\Modules\Desk\Models\DeskCase;
use App\Modules\Desk\Models\DeskCategory;
use App\Modules\Desk\Models\DeskComment;
use App\Modules\Desk\Support\Sla;
use Illuminate\Support\Carbon;

/**
 * JSON of a case. SLA flags are computed on every read (never stored). The detail adds the thread: the requester
 * gets public replies only — internal notes are not in the payload at all.
 */
final class DeskCasePresenter
{
    /**
     * @param  array<int, string>  $articleTitles  published article id → title (links in replies)
     * @return array<string, mixed>
     */
    public static function present(DeskCase $case, bool $hr, bool $detailed = false, array $articleTitles = [], ?Carbon $now = null): array
    {
        $sla = Sla::of($case->created_at, $case->category->first_response_hours, $case->category->resolve_hours, $case->first_response_at, $case->resolved_at, $now ?? Carbon::now());
        $out = [
            'id' => $case->id,
            'subject' => $case->subject,
            'status' => $case->status->value,
            'category' => ['id' => $case->category->id, 'name' => $case->category->name],
            'employee' => ['id' => $case->employee->id, 'full_name' => $case->employee->full_name],
            'assignee' => $case->assignee === null ? null : ['id' => $case->assignee->id, 'name' => $case->assignee->name],
            'created_at' => $case->created_at->toIso8601String(),
            'first_response_at' => $case->first_response_at?->toIso8601String(),
            'resolved_at' => $case->resolved_at?->toIso8601String(),
            'closed_at' => $case->closed_at?->toIso8601String(),
            'sla' => [
                'first_response_due' => $sla['first_response_due']?->toIso8601String(),
                'resolve_due' => $sla['resolve_due']?->toIso8601String(),
                'first_response_breached' => $sla['first_response_breached'],
                'resolve_breached' => $sla['resolve_breached'],
            ],
            'can_manage' => $hr,
        ];
        if (! $detailed) {
            return $out;
        }
        $comments = $case->comments->filter(static fn (DeskComment $c): bool => $hr || ! $c->internal);

        return $out + [
            'body' => $case->body,
            'comments' => $comments->map(static fn (DeskComment $c): array => [
                'id' => $c->id,
                'body' => $c->body,
                'internal' => $c->internal,
                'author' => $c->author === null ? null : ['id' => $c->author->id, 'name' => $c->author->name],
                'mine' => $c->author_id !== null && $c->author_id === $case->employee->user_id,
                'article' => $c->article_id !== null && isset($articleTitles[$c->article_id])
                    ? ['id' => $c->article_id, 'title' => $articleTitles[$c->article_id]] : null,
                'created_at' => $c->created_at->toIso8601String(),
            ])->values()->all(),
            'attachments' => $case->attachments->map(static fn (DeskAttachment $a): array => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime' => $a->mime,
                'size' => $a->size,
                'created_at' => $a->created_at->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function category(DeskCategory $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'first_response_hours' => $c->first_response_hours,
            'resolve_hours' => $c->resolve_hours,
            'default_assignee_id' => $c->default_assignee_id,
            'active' => $c->active,
        ];
    }
}
