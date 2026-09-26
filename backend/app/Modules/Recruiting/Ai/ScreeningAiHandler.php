<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Ai;

use App\Modules\Ai\Contracts\AiResultHandler;
use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Models\AiRequest;
use App\Modules\Recruiting\Contracts\ScreeningRepository;
use App\Modules\Recruiting\Models\CandidateScreening;

/**
 * Writes the AI answer into candidate_screenings (subject "screening"): pending → done|failed, once. Parsing (and the
 * verdict derived from the score) lives in ScreeningPrompt.
 */
final readonly class ScreeningAiHandler implements AiResultHandler
{
    public const string SUBJECT = 'screening';

    public function __construct(private ScreeningRepository $screenings, private ScreeningPromptFactory $prompts) {}

    public function purpose(): AiPurpose
    {
        return AiPurpose::CandidateScreening;
    }

    public function parse(array $json, AiRequest $request): array
    {
        return ScreeningPrompt::parseJson($json);
    }

    public function apply(AiRequest $request, array $data): void
    {
        if ($request->subject_type === self::SUBJECT && $request->subject_id !== null) {
            $this->screenings->finish($request->subject_id, CandidateScreening::DONE, $data);
        }
    }

    public function failed(AiRequest $request, string $error): void
    {
        if ($request->subject_type === self::SUBJECT && $request->subject_id !== null) {
            $this->screenings->finish($request->subject_id, CandidateScreening::FAILED, ['error' => mb_substr($error, 0, 64)]);
        }
    }

    public function rebuild(AiRequest $request): ?AiPrompt
    {
        $screening = $request->subject_id === null ? null : $this->screenings->find($request->subject_id);

        return $screening === null ? null : $this->prompts->forApplication($screening->application_id);
    }
}
