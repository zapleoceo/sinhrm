<?php

declare(strict_types=1);

/*
 * Known module-boundary violations (ModuleBoundariesTest): a file of one module importing another module's
 * Models, Services, Repositories or Http directly instead of its Contracts. Baseline of 2026-10-02
 * (docs/architecture/overview.md, "Границы модулей"). The list may only SHRINK: a new import fails the test, and an entry that
 * is no longer in the code must be deleted here (the test says which).
 */

return [
    'Assets/Http/Requests/AssetMoveRequest.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Assets/Models/Asset.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Assets/Models/AssetAssignment.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Assets/Services/AssetService.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Assets/Workflows/CollectAssetsExecutor.php' => [
        'App\Modules\Workflows\Services\AssigneeResolver',
    ],
    'Assistant/Ai/AssistantChatHandler.php' => [
        'App\Modules\Ai\Models\AiRequest',
    ],
    'Assistant/Ai/AssistantVoiceHandler.php' => [
        'App\Modules\Ai\Models\AiRequest',
    ],
    'Assistant/Ai/QuipsHandler.php' => [
        'App\Modules\Ai\Models\AiRequest',
    ],
    'Assistant/Services/McpTokenService.php' => [
        'App\Modules\Auth\Services\PersonalTokens',
    ],
    'Audit/Privacy/AuditPersonalData.php' => [
        'App\Modules\Recruiting\Models\Application',
    ],
    'Audit/Providers/AuditServiceProvider.php' => [
        'App\Modules\Ai\Models\AiPromptVersion',
        'App\Modules\Documents\Models\Document',
        'App\Modules\HiringRequests\Models\HiringApproval',
        'App\Modules\HiringRequests\Models\HiringRequest',
        'App\Modules\Integrations\Models\Integration',
        'App\Modules\Integrations\Models\IntegrationSecret',
        'App\Modules\People\Models\Employee',
        'App\Modules\People\Models\EmployeeCompensation',
        'App\Modules\Recruiting\Models\Application',
        'App\Modules\Recruiting\Models\Candidate',
        'App\Modules\Recruiting\Models\Vacancy',
        'App\Modules\TimeOff\Models\LeaveRequest',
        'App\Modules\Workflows\Models\WorkflowTemplate',
    ],
    'Audit/Support/SecretAuditObserver.php' => [
        'App\Modules\Integrations\Models\IntegrationSecret',
    ],
    'Channels/DTO/WebhookResult.php' => [
        'App\Modules\Recruiting\Models\Touchpoint',
    ],
    'Channels/Http/Controllers/ChannelAdminController.php' => [
        'App\Modules\Recruiting\Http\Resources\TouchpointResource',
    ],
    'Channels/Http/Controllers/ChannelMessageController.php' => [
        'App\Modules\Recruiting\Http\Resources\TouchpointResource',
        'App\Modules\Recruiting\Models\Candidate',
    ],
    'Channels/Http/Requests/SimulateRequest.php' => [
        'App\Modules\Recruiting\Models\Candidate',
    ],
    'Channels/Services/CallService.php' => [
        'App\Modules\Recruiting\Models\Candidate',
    ],
    'Channels/Services/DemoSeedFactory.php' => [
        'App\Modules\Recruiting\Models\Candidate',
    ],
    'Channels/Services/MessageService.php' => [
        'App\Modules\Recruiting\Models\Candidate',
        'App\Modules\Recruiting\Models\Touchpoint',
    ],
    'Core/Services/Demo/DemoDataService.php' => [
        'App\Modules\Pulse\Models\SurveyWave',
        'App\Modules\Pulse\Services\ResponseService',
        'App\Modules\Pulse\Services\WaveLifecycle',
        'App\Modules\Recruiting\Models\Candidate',
        'App\Modules\Recruiting\Models\Vacancy',
        'App\Modules\Recruiting\Services\RecruitingDemoData',
        'App\Modules\Recruiting\Services\VacancyService',
    ],
    'Desk/Http/Controllers/DeskCaseController.php' => [
        'App\Modules\Documents\Http\Requests\UploadDocumentFileRequest',
    ],
    'Desk/Models/DeskCase.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Desk/Services/DeskService.php' => [
        'App\Modules\Documents\Repositories\DatabaseDocumentStorage',
    ],
    'Documents/Http/Requests/CreateDocumentRequest.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Documents/Http/Requests/PreviewTemplateRequest.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Documents/Models/Document.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Documents/Services/DocumentService.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Documents/Services/DocumentTemplateService.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Documents/Services/DocumentVariables.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'GoogleWorkspace/Http/Controllers/MeetingController.php' => [
        'App\Modules\Recruiting\Http\Resources\TouchpointResource',
        'App\Modules\Recruiting\Models\Candidate',
    ],
    'GoogleWorkspace/Services/MeetingService.php' => [
        'App\Modules\Recruiting\Models\Candidate',
        'App\Modules\Recruiting\Models\Touchpoint',
    ],
    'HiringRequests/Http/Requests/SaveHiringRequestRequest.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\Department',
        'App\Modules\Directory\Models\Position',
        'App\Modules\People\Models\Employee',
    ],
    'HiringRequests/Models/HiringRequest.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\Department',
        'App\Modules\Directory\Models\Position',
        'App\Modules\People\Models\Employee',
        'App\Modules\Recruiting\Models\Vacancy',
    ],
    'HiringRequests/Services/HiringRequestService.php' => [
        'App\Modules\Recruiting\Services\VacancyService',
    ],
    'MailAgent/Ai/MailClassificationAiHandler.php' => [
        'App\Modules\Ai\Models\AiRequest',
    ],
    'MailAgent/Services/MailMessageProcessor.php' => [
        'App\Modules\Recruiting\Models\Touchpoint',
    ],
    'Overview/Services/DashboardService.php' => [
        'App\Modules\Recruiting\Models\Application',
        'App\Modules\Scripts\Models\Task',
    ],
    'Overview/Services/DayRouteService.php' => [
        'App\Modules\Scripts\Models\Task',
    ],
    'People/Http/Controllers/EmployeeHistoryController.php' => [
        'App\Modules\Audit\Http\Requests\HistoryRequest',
        'App\Modules\Audit\Http\Resources\AuditEntryResource',
    ],
    'People/Http/Controllers/HireController.php' => [
        'App\Modules\Recruiting\Models\Application',
    ],
    'People/Http/Requests/BulkEmployeesRequest.php' => [
        'App\Modules\Directory\Models\Department',
        'App\Modules\Directory\Models\Position',
    ],
    'People/Http/Requests/SaveEmployeeRequest.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\Department',
        'App\Modules\Directory\Models\Position',
    ],
    'People/Models/Employee.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\Department',
        'App\Modules\Directory\Models\Position',
    ],
    'People/Services/HireService.php' => [
        'App\Modules\Recruiting\Models\Application',
    ],
    'Perform/Contracts/ReviewRepository.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Http/Requests/AddAssignmentRequest.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Http/Requests/CreateOneOnOneRequest.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Http/Requests/GiveFeedbackRequest.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Http/Requests/SaveCycleRequest.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\Department',
    ],
    'Perform/Http/Requests/SaveKpiRequest.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Http/Requests/SaveObjectiveRequest.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\Department',
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Http/Requests/SavePlanRequest.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Models/DevelopmentPlan.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Models/Feedback.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Models/Kpi.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Models/Objective.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Models/OneOnOne.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Models/ReviewAssignment.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Repositories/EloquentReviewRepository.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Perform/Services/ReviewSetupService.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Pulse/Http/Requests/CreateWaveRequest.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\Department',
    ],
    'Pulse/Repositories/EloquentResponseRepository.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\Department',
    ],
    'Pulse/Repositories/EloquentWaveMemberRepository.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Pulse/Services/LifecycleSurveys.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Pulse/Services/MoodAlerts.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Pulse/Services/MoodService.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Pulse/Services/ResponseService.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Pulse/Services/WaveMembership.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Pulse/Support/WaveAudience.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Recruiting/Ai/ScreeningAiHandler.php' => [
        'App\Modules\Ai\Models\AiRequest',
    ],
    'Recruiting/Ai/VacancyTextHandler.php' => [
        'App\Modules\Ai\Models\AiRequest',
    ],
    'Recruiting/Database/Factories/VacancyFactory.php' => [
        'App\Modules\Directory\Models\Branch',
    ],
    'Recruiting/Http/Controllers/CandidateHistoryController.php' => [
        'App\Modules\Audit\Http\Requests\HistoryRequest',
        'App\Modules\Audit\Http\Resources\AuditEntryResource',
    ],
    'Recruiting/Http/Requests/SaveCandidateRequest.php' => [
        'App\Modules\Directory\Models\City',
    ],
    'Recruiting/Http/Requests/SaveVacancyRequest.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\City',
        'App\Modules\Directory\Models\Department',
        'App\Modules\Directory\Models\Position',
        'App\Modules\Directory\Models\VacancyCategory',
    ],
    'Recruiting/Http/Requests/SaveVacancyTemplateRequest.php' => [
        'App\Modules\Directory\Models\Department',
        'App\Modules\Directory\Models\Position',
    ],
    'Recruiting/Http/Requests/VacancyTextRequest.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\VacancyCategory',
    ],
    'Recruiting/Models/Candidate.php' => [
        'App\Modules\Directory\Models\City',
    ],
    'Recruiting/Models/Vacancy.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\City',
        'App\Modules\Directory\Models\Department',
        'App\Modules\Directory\Models\Position',
        'App\Modules\Directory\Models\VacancyCategory',
    ],
    'Recruiting/Providers/RecruitingServiceProvider.php' => [
        'App\Modules\SafeSpeak\Http\Middleware\ForceJson',
    ],
    'Recruiting/Services/CareerSiteService.php' => [
        'App\Modules\Documents\Repositories\DatabaseDocumentStorage',
    ],
    'Recruiting/Services/ExtensionTokenService.php' => [
        'App\Modules\Auth\Services\PersonalTokens',
    ],
    'Recruiting/Services/OfferService.php' => [
        'App\Modules\Channels\Services\MessageService',
    ],
    'Recruiting/Services/RecruitingDemoData.php' => [
        'App\Modules\Directory\Models\Branch',
        'App\Modules\Directory\Models\City',
        'App\Modules\Directory\Models\Position',
    ],
    'Recruiting/Services/VacancyTextService.php' => [
        'App\Modules\Ai\Models\AiRequest',
    ],
    'Reports/Definitions/AbstractTimeReport.php' => [
        'App\Modules\Time\Services\TimeReportService',
    ],
    'Reports/Definitions/ChannelEffectivenessReport.php' => [
        'App\Modules\Recruiting\Services\ReportService',
    ],
    'Reports/Definitions/MoodTrendReport.php' => [
        'App\Modules\Pulse\Services\MoodService',
    ],
    'Reports/Definitions/RecruiterTouchesReport.php' => [
        'App\Modules\Recruiting\Services\ReportService',
    ],
    'Reports/Definitions/RecruitingFunnelReport.php' => [
        'App\Modules\Recruiting\Services\ReportService',
    ],
    'Reports/Definitions/RejectReasonsReport.php' => [
        'App\Modules\Recruiting\Services\ReportService',
    ],
    'Reports/Definitions/ScriptScoresReport.php' => [
        'App\Modules\Scripts\Services\ScriptReportService',
    ],
    'Reports/Definitions/SourceEffectivenessReport.php' => [
        'App\Modules\Recruiting\Services\ReportService',
    ],
    'Scripts/Ai/ScriptEvaluationAiHandler.php' => [
        'App\Modules\Ai\Models\AiRequest',
    ],
    'Scripts/Http/Controllers/CandidateScriptController.php' => [
        'App\Modules\Recruiting\Models\Candidate',
        'App\Modules\Recruiting\Models\Touchpoint',
    ],
    'Scripts/Http/Controllers/ScriptReportController.php' => [
        'App\Modules\Recruiting\Http\Requests\ReportRequest',
    ],
    'Scripts/Models/ScriptEvaluation.php' => [
        'App\Modules\Recruiting\Models\Touchpoint',
    ],
    'Scripts/Models/Task.php' => [
        'App\Modules\People\Models\Employee',
        'App\Modules\Recruiting\Models\Application',
        'App\Modules\Recruiting\Models\Candidate',
    ],
    'Scripts/Services/AiScriptEvaluator.php' => [
        'App\Modules\Recruiting\Models\Touchpoint',
    ],
    'Scripts/Services/EvaluationService.php' => [
        'App\Modules\Recruiting\Models\Touchpoint',
    ],
    'Scripts/Services/TemplateService.php' => [
        'App\Modules\Recruiting\Models\Candidate',
    ],
    'Time/Http/Requests/SaveScheduleRequest.php' => [
        'App\Modules\Directory\Models\Branch',
    ],
    'Time/Models/Timesheet.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Time/Models/WorkSchedule.php' => [
        'App\Modules\Directory\Models\Branch',
    ],
    'Time/Services/TimeReminderJob.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Time/Services/TimesheetService.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Time/Services/WeekSummaryService.php' => [
        'App\Modules\People\Models\Employee',
        'App\Modules\TimeOff\Models\LeaveRequest',
    ],
    'TimeOff/Http/Controllers/LeaveRequestController.php' => [
        'App\Modules\People\Http\Requests\DecisionRequest',
    ],
    'TimeOff/Http/Requests/AdjustBalanceRequest.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'TimeOff/Http/Requests/SaveHolidayRequest.php' => [
        'App\Modules\Directory\Models\Branch',
    ],
    'TimeOff/Http/Requests/SavePolicyRequest.php' => [
        'App\Modules\Directory\Models\Branch',
    ],
    'TimeOff/Models/Holiday.php' => [
        'App\Modules\Directory\Models\Branch',
    ],
    'TimeOff/Models/LeavePolicy.php' => [
        'App\Modules\Directory\Models\Branch',
    ],
    'TimeOff/Models/LeaveRequest.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'TimeOff/Services/AccrualService.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'TimeOff/Services/BalanceService.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'TimeOff/Services/EmployeeResolver.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'TimeOff/Services/LeaveRequestService.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Users/Http/Requests/UpdateUserRequest.php' => [
        'App\Modules\Directory\Models\Branch',
    ],
    'Users/Http/Resources/UserResource.php' => [
        'App\Modules\Directory\Models\Branch',
    ],
    'Workflows/DTO/StepContext.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Workflows/Executors/CreateDocumentExecutor.php' => [
        'App\Modules\Documents\Services\DocumentService',
    ],
    'Workflows/Http/Requests/StartRunRequest.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Workflows/Models/WorkflowRun.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Workflows/Services/AssigneeResolver.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Workflows/Services/WorkflowRunService.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Workflows/Services/WorkflowStarter.php' => [
        'App\Modules\People\Models\Employee',
    ],
    'Workflows/Services/WorkflowTriggers.php' => [
        'App\Modules\People\Models\Employee',
    ],
];
