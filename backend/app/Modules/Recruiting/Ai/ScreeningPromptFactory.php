<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Ai;

use App\Modules\Ai\DTO\AiPrompt;
use App\Modules\Recruiting\Contracts\ApplicationRepository;
use App\Modules\Recruiting\Contracts\ScreeningRepository;

/**
 * DB → ScreeningInput → prompt for an application: vacancy (title, position, department, description), candidate
 * (city, tags; the name only to cut it out of the materials) and the newest materials (notes incl. the clipper
 * CV summary, the candidate's own messages and transcripts). No contacts, no authors, no employee data.
 */
final readonly class ScreeningPromptFactory
{
    /** Newest touches taken as materials (then cut to ScreeningPrompt::MATERIALS_LIMIT characters). */
    public const int MATERIALS_COUNT = 20;

    public function __construct(private ApplicationRepository $applications, private ScreeningRepository $screenings) {}

    public function forApplication(int $applicationId): ?AiPrompt
    {
        $application = $this->applications->find($applicationId);
        if ($application === null) {
            return null;
        }
        $application->loadMissing(['vacancy.position', 'vacancy.department', 'candidate.city']);
        $vacancy = $application->vacancy;
        $candidate = $application->candidate;

        return ScreeningPrompt::build(new ScreeningInput(
            vacancyTitle: $vacancy->title,
            position: $vacancy->position?->name,
            department: $vacancy->department?->name,
            requirements: $vacancy->description,
            city: $candidate->city?->name,
            tags: $candidate->tags ?? [],
            names: [$candidate->full_name],
            materials: $this->screenings->materials($candidate->id, self::MATERIALS_COUNT),
        ));
    }
}
