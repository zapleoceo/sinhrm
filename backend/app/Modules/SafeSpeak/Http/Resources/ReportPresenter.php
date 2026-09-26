<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Http\Resources;

use App\Modules\SafeSpeak\Models\SafeSpeakMessage;
use App\Modules\SafeSpeak\Models\SafeSpeakReport;

/**
 * JSON of a report. The reporter sees no internal id and no handler identity; handlers see the id (inbox)
 * but nothing about the reporter — there is nothing stored to show.
 */
final class ReportPresenter
{
    /** @return array<string, mixed> */
    public static function forReporter(SafeSpeakReport $r): array
    {
        return self::base($r) + ['messages' => self::messages($r)];
    }

    /** @return array<string, mixed> */
    public static function forHandler(SafeSpeakReport $r, bool $detailed): array
    {
        $out = ['id' => $r->id] + self::base($r);

        return $detailed ? $out + ['messages' => self::messages($r)] : $out + ['messages_count' => (int) ($r->messages_count ?? 0)];
    }

    /** @return array<string, mixed> */
    private static function base(SafeSpeakReport $r): array
    {
        return [
            'category' => $r->category->value,
            'subject' => $r->subject,
            'status' => $r->status->value,
            'created_on' => $r->created_on->toDateString(),
            'updated_on' => $r->updated_on->toDateString(),
        ];
    }

    /** @return list<array{author: string, body: string, created_on: string}> */
    private static function messages(SafeSpeakReport $r): array
    {
        return $r->messages()->get()->map(static fn (SafeSpeakMessage $m): array => [
            'author' => $m->author,
            'body' => $m->body,
            'created_on' => $m->created_on->toDateString(),
        ])->values()->all();
    }
}
