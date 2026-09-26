<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Privacy;

use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\DTO\DataSubject;
use App\Modules\Core\Enums\DataSubjectType;
use App\Modules\MailAgent\Models\MailMessage;
use Illuminate\Database\Eloquent\Builder;

/**
 * MailAgent's share: the processed-mail log keeps sender and subject of letters matched to the candidate (no bodies).
 * Erase wipes sender and subject; the row stays (idempotency by Gmail id, sync stats). The letters themselves live
 * in the company Gmail box — not our storage, delete them there if needed.
 */
final readonly class MailPersonalData implements PersonalDataProvider
{
    public function section(): string
    {
        return 'mail_log';
    }

    public function blocker(DataSubject $subject, bool $erase): ?string
    {
        return null;
    }

    public function export(DataSubject $subject): array
    {
        return $this->messages($subject)->orderBy('received_at')->get()->map(static fn (MailMessage $m): array => [
            'received_at' => $m->received_at->toIso8601String(),
            'sender' => $m->sender,
            'subject' => $m->subject,
            'kind' => $m->kind,
        ])->all();
    }

    public function erase(DataSubject $subject): array
    {
        return ['mail_messages' => $this->messages($subject)->update(['sender' => null, 'subject' => null])];
    }

    /** @return Builder<MailMessage> */
    private function messages(DataSubject $subject): Builder
    {
        return $subject->type === DataSubjectType::Candidate
            ? MailMessage::query()->where('candidate_id', $subject->id)
            : MailMessage::query()->whereRaw('1 = 0');
    }
}
