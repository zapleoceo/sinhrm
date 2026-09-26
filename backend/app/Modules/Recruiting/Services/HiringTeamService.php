<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Services;

use App\Models\User;
use App\Modules\Recruiting\Contracts\HiringTeamRepository;
use App\Modules\Recruiting\Models\Application;
use Psr\Log\LoggerInterface;

/** Contextual recruiting roles on an application (interviewers). The hiring manager is a vacancy field. */
final readonly class HiringTeamService
{
    public function __construct(private HiringTeamRepository $team, private LoggerInterface $log) {}

    /** @param  list<int>  $userIds */
    public function assignInterviewers(User $actor, Application $application, array $userIds): Application
    {
        $application = $this->team->syncInterviewers($application, $userIds);
        $this->log->info('recruiting.interviewers_assigned', ['application_id' => $application->id, 'by' => $actor->id, 'user_ids' => $userIds]);

        return $application;
    }
}
