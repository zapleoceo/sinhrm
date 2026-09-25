<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Repositories;

use App\Modules\MailAgent\Contracts\UnknownSenderRepository;
use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;
use App\Modules\MailAgent\Models\UnknownSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class EloquentUnknownSenderRepository implements UnknownSenderRepository
{
    public function list(int $limit): Collection
    {
        return UnknownSender::query()->orderByDesc('count')->orderByDesc('last_seen_at')->limit($limit)->get();
    }

    public function find(int $id): ?UnknownSender
    {
        return UnknownSender::query()->find($id);
    }

    public function touch(string $email, string $subject, Carbon $at, ?SenderKind $suggestedKind, ?ParserKey $suggestedParser): void
    {
        $existing = UnknownSender::query()->where('email', $email)->first();
        if ($existing !== null) {
            UnknownSender::query()->whereKey($existing->id)->increment('count', 1, ['last_seen_at' => $at]);

            return;
        }
        UnknownSender::query()->insertOrIgnore([[
            'email' => $email,
            'sample_subject' => mb_substr($subject, 0, 255),
            'first_seen_at' => $at,
            'last_seen_at' => $at,
            'count' => 1,
            'suggested_kind' => $suggestedKind?->value,
            'suggested_parser' => $suggestedParser?->value,
            'created_at' => $at,
            'updated_at' => $at,
        ]]);
    }

    public function delete(UnknownSender $sender): void
    {
        $sender->delete();
    }

    public function deleteDomain(string $domain): int
    {
        $domain = mb_strtolower($domain);

        return UnknownSender::query()
            ->where(fn (Builder $q) => $q->where('email', 'like', '%@'.$domain)->orWhere('email', 'like', '%.'.$domain))
            ->delete();
    }

    public function count(): int
    {
        return UnknownSender::query()->count();
    }
}
