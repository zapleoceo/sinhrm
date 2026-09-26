<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Contracts;

use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;
use App\Modules\MailAgent\Models\UnknownSender;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

interface UnknownSenderRepository
{
    /** @return Collection<int, UnknownSender> most frequent first */
    public function list(int $limit): Collection;

    public function find(int $id): ?UnknownSender;

    /** Insert or count + 1 (keeps the first subject as the sample); returns the row. */
    public function touch(string $email, string $subject, Carbon $at, ?SenderKind $suggestedKind, ?ParserKey $suggestedParser): ?UnknownSender;

    /**
     * AI suggestion state of a queued sender (status pending|done|failed|skipped). No-op when the row is gone (a rule was
     * created meanwhile) or when it already holds the result of a newer AI request.
     *
     * @param  array<string, mixed>  $fields  ai_kind, ai_parser, ai_confidence, ai_extracted
     */
    public function saveAiSuggestion(int $id, ?int $requestId, string $status, array $fields): void;

    /** @return Collection<int, UnknownSender> queued senders the AI was never asked about, most frequent first */
    public function withoutAiSuggestion(int $limit): Collection;

    public function delete(UnknownSender $sender): void;

    /** Removes every queued sender of the domain and its subdomains (after a "@domain" rule). */
    public function deleteDomain(string $domain): int;

    public function count(): int;
}
