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

    /** Insert or count + 1 (keeps the first subject as the sample). */
    public function touch(string $email, string $subject, Carbon $at, ?SenderKind $suggestedKind, ?ParserKey $suggestedParser): void;

    public function delete(UnknownSender $sender): void;

    /** Removes every queued sender of the domain and its subdomains (after a "@domain" rule). */
    public function deleteDomain(string $domain): int;

    public function count(): int;
}
