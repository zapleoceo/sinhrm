<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Services;

use App\Models\User;
use App\Modules\People\Services\PeopleScope;
use App\Modules\SafeSpeak\Contracts\SafeSpeakRepository;
use App\Modules\SafeSpeak\Enums\ReportCategory;
use App\Modules\SafeSpeak\Enums\ReportStatus;
use App\Modules\SafeSpeak\Exceptions\SafeSpeakException;
use App\Modules\SafeSpeak\Models\SafeSpeakMessage;
use App\Modules\SafeSpeak\Models\SafeSpeakReport;
use App\Modules\SafeSpeak\Support\AccessCode;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Anonymous reports. The service never receives who the reporter is: no user, no IP — only a hashed client bucket
 * for rate limiting. Handlers are superadmin/admin users with the explicit safe_speak_handler flag.
 * Nothing here is logged.
 */
final readonly class SafeSpeakService
{
    /** New reports per client bucket per hour. */
    public const int SUBMITS_PER_HOUR = 5;

    /** Wrong access codes per client bucket per 15 minutes (brute-force brake); successful lookups do not count. */
    public const int FAILED_CODES = 10;

    public const int FAILED_WINDOW_SECONDS = 900;

    public const int LIMIT = 300;

    public function __construct(
        private SafeSpeakRepository $reports,
        private PeopleScope $scope,
        private RateLimiter $limiter,
        private string $appKey,
    ) {}

    public function isHandler(User $user): bool
    {
        return $user->safe_speak_handler && $this->scope->isAdmin($user);
    }

    /** @return array{code: string, report: SafeSpeakReport} the code is returned once and never again */
    public function submit(string $bucket, ReportCategory $category, string $subject, string $body, ?Carbon $now = null): array
    {
        $key = 'safe-speak:submit:'.$bucket;
        if ($this->limiter->tooManyAttempts($key, self::SUBMITS_PER_HOUR)) {
            throw SafeSpeakException::tooManyAttempts($this->limiter->availableIn($key));
        }
        $this->limiter->hit($key, 3600);
        $today = ($now ?? Carbon::now())->toDateString();
        $code = AccessCode::generate();
        $report = $this->reports->create([
            'access_code_hash' => AccessCode::hash($code, $this->appKey),
            'category' => $category->value,
            'subject' => $subject,
            'status' => ReportStatus::New->value,
            'created_on' => $today,
            'updated_on' => $today,
        ], $body);

        return ['code' => $code, 'report' => $report];
    }

    /** The report behind a code, for the reporter. Wrong codes count against the bucket; after the limit → 429. */
    public function byCode(string $bucket, string $code): SafeSpeakReport
    {
        $key = 'safe-speak:code:'.$bucket;
        if ($this->limiter->tooManyAttempts($key, self::FAILED_CODES)) {
            throw SafeSpeakException::tooManyAttempts($this->limiter->availableIn($key));
        }
        $report = AccessCode::wellFormed($code) ? $this->reports->findByCodeHash(AccessCode::hash($code, $this->appKey)) : null;
        if ($report === null) {
            $this->limiter->hit($key, self::FAILED_WINDOW_SECONDS);

            throw SafeSpeakException::invalidCode();
        }

        return $report;
    }

    public function reporterReply(string $bucket, string $code, string $body, ?Carbon $now = null): SafeSpeakReport
    {
        $report = $this->byCode($bucket, $code);
        if ($report->status === ReportStatus::Closed) {
            throw SafeSpeakException::closed();
        }
        $this->reports->addMessage($report, SafeSpeakMessage::REPORTER, null, $body, ($now ?? Carbon::now())->toDateString());

        return $report;
    }

    /** @return Collection<int, SafeSpeakReport> */
    public function inbox(?ReportStatus $status): Collection
    {
        return $this->reports->inbox($status?->value, self::LIMIT);
    }

    public function find(int $id): SafeSpeakReport
    {
        return $this->reports->find($id) ?? abort(404);
    }

    public function handlerReply(User $handler, SafeSpeakReport $report, string $body, ?Carbon $now = null): SafeSpeakReport
    {
        if ($report->status === ReportStatus::Closed) {
            throw SafeSpeakException::closed();
        }
        $this->reports->addMessage($report, SafeSpeakMessage::HANDLER, $handler->id, $body, ($now ?? Carbon::now())->toDateString());
        if ($report->status === ReportStatus::New) {
            $this->reports->update($report, ['status' => ReportStatus::InReview->value]);
        }

        return $report;
    }

    public function setStatus(SafeSpeakReport $report, ReportStatus $status, ?Carbon $now = null): SafeSpeakReport
    {
        return $this->reports->update($report, ['status' => $status->value, 'updated_on' => ($now ?? Carbon::now())->toDateString()]);
    }
}
