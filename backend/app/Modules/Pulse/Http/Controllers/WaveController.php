<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Controllers;

use App\Models\User;
use App\Modules\Pulse\Http\Requests\ReportRequest;
use App\Modules\Pulse\Http\Requests\RespondRequest;
use App\Modules\Pulse\Models\SurveyWave;
use App\Modules\Pulse\Services\ResponseService;
use App\Modules\Pulse\Services\SurveyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Respondent side (every active user with an employee record): my open waves, the form, answering.
 * Reports (results, comparison): admins, and managers for their own department — ResponseService.
 */
final class WaveController
{
    public function __construct(private readonly ResponseService $responses, private readonly SurveyService $surveys) {}

    public function mine(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => array_map(
            static fn (array $item): array => self::respondentView($item['wave'], $item['responded']),
            $this->responses->mine($this->actor($request)),
        )]);
    }

    public function form(Request $request, int $id): JsonResponse
    {
        ['wave' => $wave, 'responded' => $responded] = $this->responses->form($this->actor($request), $id);

        return new JsonResponse(['data' => self::respondentView($wave, $responded) + [
            'description' => $wave->survey->description,
            'questions' => $wave->survey->questions,
        ]]);
    }

    public function respond(RespondRequest $request, int $id): Response
    {
        $this->responses->respond($this->actor($request), $id, $request->answers());

        return response()->noContent(201);
    }

    public function results(ReportRequest $request, int $id): JsonResponse
    {
        $wave = $this->surveys->findWave($id);

        return new JsonResponse(['data' => ['wave' => self::reportView($wave)]
            + $this->responses->results($this->actor($request), $wave, $request->breakdown())]);
    }

    public function compare(ReportRequest $request, int $id): JsonResponse
    {
        $wave = $this->surveys->findWave($id);
        $with = $request->withWave() === null ? null : $this->surveys->findWave((int) $request->withWave());

        return new JsonResponse(['data' => $this->responses->compare($this->actor($request), $wave, $with, $request->breakdown() ?? 'department')]);
    }

    /** @return array<string, mixed> what a respondent may know about a wave (no counts, no audience) */
    private static function respondentView(SurveyWave $wave, bool $responded): array
    {
        return [
            'id' => $wave->id,
            'title' => $wave->survey->title,
            'type' => $wave->survey->type->value,
            'anonymous' => $wave->anonymous,
            'ends_at' => $wave->ends_at->toIso8601String(),
            'responded' => $responded,
        ];
    }

    /** @return array<string, mixed> */
    private static function reportView(SurveyWave $wave): array
    {
        return [
            'id' => $wave->id,
            'survey' => ['id' => $wave->survey->id, 'title' => $wave->survey->title, 'type' => $wave->survey->type->value],
            'anonymous' => $wave->anonymous,
            'min_group_size' => $wave->min_group_size,
            'starts_at' => $wave->starts_at->toIso8601String(),
            'ends_at' => $wave->ends_at->toIso8601String(),
            'status' => $wave->status->value,
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
