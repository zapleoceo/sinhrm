<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Services;

use App\Models\User;
use App\Modules\Auth\Contracts\UserRepository;
use App\Modules\GoogleWorkspace\Contracts\GmailClient;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Services\GoogleConnectionStore;
use App\Modules\MailAgent\Contracts\MailLogRepository;
use App\Modules\MailAgent\Enums\MailOutcome;
use App\Modules\MailAgent\Support\MailRecord;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One sync of the connected Gmail box (cron "mail.sync" every 30 min, or "Sync now"):
 * users.messages.list q="newer_than:2d -in:chats [after:<cursor − 60 s>]" → oldest first → skip ids already in
 * mail_messages → messages.get(format=full) → MailMessageProcessor → mail_messages row.
 * Idempotent: the Gmail id is unique in mail_messages and is the touchpoint external_id. The cursor (max internalDate)
 * moves only when every listed message was handled, so a cut-off run is simply continued next time.
 */
final readonly class MailSyncService
{
    public const string BASE_QUERY = 'newer_than:2d -in:chats';

    public const int MAX_MESSAGES = 100;

    public const int PAGE_SIZE = 50;

    /** Budget of one run; the Vercel function lives 60 s. */
    public const int TOTAL_SECONDS = 40;

    /** Overlap with the previous run (Gmail "after:" has 1-second granularity and delivery can lag). */
    public const int CURSOR_OVERLAP_SECONDS = 60;

    public function __construct(
        private GmailClient $gmail,
        private GoogleConnectionStore $connections,
        private MailLogRepository $log,
        private MailMessageProcessor $processor,
        private UserRepository $users,
        private LoggerInterface $logger,
    ) {}

    public function connected(): bool
    {
        return $this->connections->state(GoogleService::Gmail)->usable;
    }

    /**
     * @return array<string, int>
     *
     * @throws GoogleException not connected / reconnect_required / network (the run is stored with the error code)
     */
    public function sync(string $trigger, ?User $actor = null): array
    {
        if (! $this->connected()) {
            throw GoogleException::notConnected(GoogleService::Gmail->value);
        }
        $actor ??= $this->backgroundActor();
        $run = $this->log->startRun($trigger, $actor?->id, Carbon::now());
        $counts = array_fill_keys(['listed', 'processed', 'duplicates', 'errors', 'tasks', ...MailOutcome::values()], 0);
        $cursor = $this->log->cursor();
        $maxSeen = $cursor;
        $complete = true;

        try {
            $ids = $this->listIds($cursor, $complete);
            $counts['listed'] = count($ids);
            $deadline = microtime(true) + self::TOTAL_SECONDS;
            foreach ($ids as $id) {
                if (microtime(true) > $deadline) {
                    $complete = false;
                    break;
                }
                if ($this->log->isProcessed($id)) {
                    $counts['duplicates']++;

                    continue;
                }
                $handled = $this->handle($id, $actor, $counts);
                if ($handled === null) {
                    $complete = false;

                    continue;
                }
                $maxSeen = max($maxSeen ?? 0, $handled);
            }
        } catch (GoogleException $e) {
            $this->log->finishRun($run, $counts, null, $e->errorCode, Carbon::now());
            throw $e;
        }
        $this->log->finishRun($run, $counts, $complete ? $maxSeen : $cursor, null, Carbon::now());

        return $counts;
    }

    /**
     * Newest-first pages until MAX_MESSAGES, returned oldest first.
     *
     * @return list<string>
     */
    private function listIds(?int $cursor, bool &$complete): array
    {
        $query = self::BASE_QUERY;
        if ($cursor !== null) {
            $query .= ' after:'.max(0, intdiv($cursor, 1000) - self::CURSOR_OVERLAP_SECONDS);
        }
        $ids = [];
        $page = null;
        do {
            $result = $this->gmail->listIds($query, self::PAGE_SIZE, $page);
            $ids = [...$ids, ...$result['ids']];
            $page = $result['next'];
        } while ($page !== null && count($ids) < self::MAX_MESSAGES);
        if ($page !== null || count($ids) > self::MAX_MESSAGES) {
            $complete = false;
        }

        return array_reverse(array_slice(array_values(array_unique($ids)), 0, self::MAX_MESSAGES));
    }

    /**
     * @param  array<string, int>  $counts
     * @return int|null internalDate (ms) of the handled message; null = failed, retry next run
     *
     * @throws GoogleException reconnect_required / not connected abort the run
     */
    private function handle(string $id, ?User $actor, array &$counts): ?int
    {
        try {
            $message = $this->gmail->get($id);
            $result = $this->processor->process($message, $actor);
        } catch (GoogleException $e) {
            if (in_array($e->errorCode, ['reconnect_required', 'google_unauthorized'], true) || str_ends_with($e->errorCode, '_not_connected')) {
                throw $e;
            }
            $counts['errors']++;
            $this->logger->warning('mail.message_failed', ['code' => $e->errorCode]);

            return null;
        } catch (Throwable $e) {
            // Class only: messages of lower layers may contain mail content.
            $counts['errors']++;
            $this->logger->warning('mail.message_failed', ['exception' => $e::class]);

            return null;
        }

        $this->log->record(MailRecord::attributes($message, $result));
        $counts['processed']++;
        $counts[$result->outcome->value]++;
        if ($result->taskCreated) {
            $counts['tasks']++;
        }

        return (int) $message->receivedAt->valueOf();
    }

    /** Background runs act as the superadmin who connected Gmail (owner/creator of new candidates), if still active. */
    public function backgroundActor(): ?User
    {
        $id = $this->connections->connectedBy(GoogleService::Gmail);
        $user = $id === null ? null : $this->users->find($id);

        return $user !== null && $user->isActive() ? $user : null;
    }
}
