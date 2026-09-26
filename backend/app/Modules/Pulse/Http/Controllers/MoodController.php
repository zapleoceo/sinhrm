<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Controllers;

use App\Models\User;
use App\Modules\Pulse\Http\Requests\MoodCheckinRequest;
use App\Modules\Pulse\Http\Requests\MoodReportRequest;
use App\Modules\Pulse\Http\Requests\MoodSettingsRequest;
use App\Modules\Pulse\Models\MoodCheckin;
use App\Modules\Pulse\Models\MoodSetting;
use App\Modules\Pulse\Services\MoodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mood check-in (own), own history, team trend (managers/admins), settings (write: admins). */
final class MoodController
{
    public function __construct(private readonly MoodService $mood) {}

    public function today(Request $request): JsonResponse
    {
        $state = $this->mood->today($this->actor($request));

        return new JsonResponse(['data' => [
            'ask' => $state['ask'],
            'question' => $state['question'],
            'required' => $state['required'],
            'has_employee' => $state['has_employee'],
            'today' => $state['today'] === null ? null : self::entry($state['today']),
        ]]);
    }

    public function store(MoodCheckinRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => self::entry($this->mood->checkIn($this->actor($request), $request->score(), $request->comment()))], 201);
    }

    public function me(MoodReportRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->mood->history($this->actor($request), $request->days())
            ->map(static fn (MoodCheckin $c): array => self::entry($c))->values()->all()]);
    }

    public function team(MoodReportRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->mood->team($this->actor($request), $request->weeks(), $request->branchId(), $request->departmentId())]);
    }

    public function settings(): JsonResponse
    {
        return new JsonResponse(['data' => self::settingsView($this->mood->settings())]);
    }

    public function updateSettings(MoodSettingsRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => self::settingsView($this->mood->saveSettings($request->payload()))]);
    }

    /** @return array{day: string, score: int, comment: string|null} */
    private static function entry(MoodCheckin $c): array
    {
        return ['day' => $c->day->toDateString(), 'score' => $c->score, 'comment' => $c->comment];
    }

    /** @return array<string, mixed> */
    private static function settingsView(MoodSetting $s): array
    {
        return [
            'weekdays' => $s->weekdays,
            'question' => $s->question,
            'required' => $s->required,
            'alert_drop' => (float) $s->alert_drop,
            'min_group' => $s->min_group,
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
