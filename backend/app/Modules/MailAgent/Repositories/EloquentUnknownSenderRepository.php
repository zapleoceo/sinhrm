<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Repositories;

use App\Modules\Core\Support\Database\Like;
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

    public function touch(string $email, string $subject, Carbon $at, ?SenderKind $suggestedKind, ?ParserKey $suggestedParser): ?UnknownSender
    {
        $existing = UnknownSender::query()->where('email', $email)->first();
        if ($existing !== null) {
            UnknownSender::query()->whereKey($existing->id)->increment('count', 1, ['last_seen_at' => $at]);

            return $existing;
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

        return UnknownSender::query()->where('email', $email)->first();
    }

    public function saveAiSuggestion(int $id, ?int $requestId, string $status, array $fields): void
    {
        if (is_array($fields['ai_extracted'] ?? null)) {
            // A query-builder update skips model casts.
            $fields['ai_extracted'] = json_encode($fields['ai_extracted'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        UnknownSender::query()
            ->whereKey($id)
            ->when($requestId !== null, fn ($q) => $q->where(fn (Builder $w) => $w->whereNull('ai_request_id')->orWhere('ai_request_id', '<=', $requestId)))
            ->update(['ai_status' => $status, 'ai_request_id' => $requestId] + $fields + ['updated_at' => Carbon::now()]);
    }

    public function withoutAiSuggestion(int $limit): Collection
    {
        return UnknownSender::query()->whereNull('ai_status')->orderByDesc('count')->orderByDesc('last_seen_at')->limit($limit)->get();
    }

    public function delete(UnknownSender $sender): void
    {
        $sender->delete();
    }

    public function deleteDomain(string $domain): int
    {
        // "_" and "%" are legal in a domain label, so they must stay literal in the LIKE pattern (Like::escape),
        // otherwise assigning "@a_b.example.test" would also sweep axb.example.test out of the queue.
        $domain = Like::escape(mb_strtolower($domain), Like::PORTABLE);

        return UnknownSender::query()
            ->where(fn (Builder $q) => $q->whereRaw("email like ? escape '!'", ['%@'.$domain])
                ->orWhereRaw("email like ? escape '!'", ['%.'.$domain]))
            ->delete();
    }

    public function count(): int
    {
        return UnknownSender::query()->count();
    }
}
