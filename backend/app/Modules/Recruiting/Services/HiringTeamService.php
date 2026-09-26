<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\HiringTeamRepository;
use App\Modules\Recruiting\Models\Application;
use Illuminate\Auth\Access\AuthorizationException;
use Psr\Log\LoggerInterface;

/** Contextual recruiting roles on an application (interviewers). The hiring manager is a vacancy field. */
final readonly class HiringTeamService
{
    private const int PICKER_LIMIT = 50;

    public function __construct(private HiringTeamRepository $team, private RecruitingScope $scope, private LoggerInterface $log) {}

    /**
     * Users to pick as hiring manager / interviewer. Open to recruiting writers and to hiring managers (who assign
     * interviewers on their vacancy); anyone else gets 403.
     *
     * @return list<array{id: int, name: string}>
     *
     * @throws AuthorizationException
     */
    public function assignableUsers(User $actor, ?string $query): array
    {
        if (! $this->scope->canWrite($actor) && $this->scope->for($actor)->managedVacancyIds === []) {
            throw new AuthorizationException;
        }

        return $this->team->activeUsers($query, self::PICKER_LIMIT);
    }

    /** @param  list<int>  $userIds */
    public function assignInterviewers(User $actor, Application $application, array $userIds): Application
    {
        $application = $this->team->syncInterviewers($application, $userIds);
        $this->log->info('recruiting.interviewers_assigned', ['application_id' => $application->id, 'by' => $actor->id, 'user_ids' => $userIds]);

        return $application;
    }
}
