<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Ai\Contracts\AiRequestRepository;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Exceptions\AiException;
use App\Modules\Ai\Services\AiService;
use App\Modules\Ai\Support\AiSettingsReader;
use App\Modules\Recruiting\Ai\ScreeningAiHandler;
use App\Modules\Recruiting\Ai\ScreeningPrompt;
use App\Modules\Recruiting\Ai\ScreeningPromptFactory;
use App\Modules\Recruiting\Contracts\ScreeningRepository;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\CandidateScreening;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * AI screening of applications (tz6): the candidate against the vacancy requirements → score, summary, strengths,
 * gaps, interview questions. Advisory only ("Оцінка ШІ, рішення за людиною"): nothing moves on the board by itself.
 * Manual: button in the card (waits ≤ 40 s, otherwise finished by ai.poll). Auto (setting ai_screening_auto, default
 * off): the ai.screen job screens new active applications without waiting.
 */
final readonly class ScreeningService
{
    /** Pending screenings polled once when the card list is opened (keeps the request short). */
    public const int REFRESH_LIMIT = 3;

    public const int AUTO_BATCH = 5;

    public const int AUTO_WINDOW_HOURS = 48;

    public function __construct(
        private AiService $ai,
        private AiSettingsReader $settings,
        private AiRequestRepository $requests,
        private ScreeningRepository $screenings,
        private ScreeningPromptFactory $prompts,
    ) {}

    /**
     * Starts a screening of the application (an unfinished one is returned instead: no double spending).
     *
     * @throws AiException AI off / not configured / purpose off / over the cap (the row is stored as failed)
     */
    public function start(Application $application, ?User $actor, string $trigger, int $waitSeconds = AiService::WAIT_SECONDS): CandidateScreening
    {
        $pending = $this->screenings->pendingFor($application->id);
        if ($pending !== null) {
            return $pending;
        }
        // A refusal before any request stores nothing.
        $this->ai->assertAvailable(AiPurpose::CandidateScreening);
        $input = $this->prompts->input($application->id);
        $screening = $this->screenings->create([
            'application_id' => $application->id,
            'candidate_id' => $application->candidate_id,
            'vacancy_id' => $application->vacancy_id,
            'status' => CandidateScreening::PENDING,
            'trigger' => $trigger,
            'prompt_version' => ScreeningPrompt::VERSION,
            'requested_by' => $actor?->id,
        ]);
        if ($input === null || ScreeningPrompt::insufficient($input)) {
            // No CV, notes or messages: nothing to assess, no model call.
            $this->screenings->finish($screening->id, CandidateScreening::FAILED, ['error' => 'insufficient_data']);

            return $this->screenings->find($screening->id) ?? $screening;
        }
        try {
            $outcome = $this->ai->run(ScreeningPrompt::build($input), ScreeningAiHandler::SUBJECT, $screening->id, [], $waitSeconds);
            $this->screenings->update($screening, ['ai_request_id' => $outcome->requestId]);
        } catch (AiException $e) {
            $this->screenings->finish($screening->id, CandidateScreening::FAILED, ['error' => $e->errorCode]);

            throw $e;
        }

        return $this->screenings->find($screening->id) ?? $screening;
    }

    /**
     * The latest screening of each application of the candidate; pending ones are polled once first (≤ REFRESH_LIMIT).
     *
     * @return Collection<int, CandidateScreening>
     */
    public function forCandidate(Candidate $candidate): Collection
    {
        $list = $this->screenings->latestForCandidate($candidate->id);
        $polled = 0;
        foreach ($list as $screening) {
            if ($screening->status !== CandidateScreening::PENDING || $screening->ai_request_id === null || $polled >= self::REFRESH_LIMIT) {
                continue;
            }
            $request = $this->requests->find($screening->ai_request_id);
            if ($request !== null) {
                $polled++;
                $this->ai->refresh($request);
            }
        }

        return $polled === 0 ? $list : $this->screenings->latestForCandidate($candidate->id);
    }

    /** @return array<string, int|string|bool> counters for the ai.screen job */
    public function autoScreen(Carbon $now): array
    {
        if (! $this->settings->read()->autoScreening) {
            return ['skipped' => 'auto_off'];
        }
        $reason = $this->ai->unavailableReason(AiPurpose::CandidateScreening);
        if ($reason !== null) {
            return ['skipped' => $reason];
        }
        $started = 0;
        foreach ($this->screenings->unscreenedApplications($now->copy()->subHours(self::AUTO_WINDOW_HOURS), self::AUTO_BATCH) as $application) {
            try {
                $this->start($application, null, 'auto', 0);
                $started++;
            } catch (AiException $e) {
                return ['started' => $started, 'stopped' => $e->errorCode];
            }
        }

        return ['started' => $started];
    }
}
