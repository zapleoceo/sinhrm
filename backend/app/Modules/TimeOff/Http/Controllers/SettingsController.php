<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Controllers;

use App\Models\User;
use App\Modules\TimeOff\Http\Requests\ListHolidaysRequest;
use App\Modules\TimeOff\Http\Requests\SaveHolidayRequest;
use App\Modules\TimeOff\Http\Requests\SaveLeaveTypeRequest;
use App\Modules\TimeOff\Http\Requests\SavePolicyRequest;
use App\Modules\TimeOff\Http\Resources\SettingsResources;
use App\Modules\TimeOff\Models\Holiday;
use App\Modules\TimeOff\Models\LeavePolicy;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Providers\TimeOffServiceProvider;
use App\Modules\TimeOff\Services\LeaveSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Leave types, policies, holidays. Reading types/holidays: any active user; everything else: gate timeoff-manage. */
final class SettingsController
{
    public function __construct(private readonly LeaveSettingsService $service) {}

    /** ?all=1 adds inactive types (admins only). */
    public function types(Request $request): JsonResponse
    {
        $all = $request->boolean('all') && $this->actor($request)->can(TimeOffServiceProvider::MANAGE);

        return $this->list($this->service->types($all)->map(SettingsResources::type(...))->all());
    }

    public function storeType(SaveLeaveTypeRequest $request): JsonResponse
    {
        return $this->one(SettingsResources::type($this->service->saveType($this->actor($request), null, $request->attributesToSave())), 201);
    }

    public function updateType(SaveLeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        return $this->one(SettingsResources::type($this->service->saveType($this->actor($request), $leaveType, $request->attributesToSave())));
    }

    public function policies(): JsonResponse
    {
        return $this->list($this->service->policies()->map(SettingsResources::policy(...))->all());
    }

    public function storePolicy(SavePolicyRequest $request): JsonResponse
    {
        return $this->one(SettingsResources::policy($this->service->savePolicy($this->actor($request), null, $request->attributesToSave())), 201);
    }

    public function updatePolicy(SavePolicyRequest $request, LeavePolicy $policy): JsonResponse
    {
        return $this->one(SettingsResources::policy($this->service->savePolicy($this->actor($request), $policy, $request->attributesToSave())));
    }

    public function holidays(ListHolidaysRequest $request): JsonResponse
    {
        return $this->list($this->service->holidays($request->year(), $request->branchId())->map(SettingsResources::holiday(...))->all());
    }

    public function storeHoliday(SaveHolidayRequest $request): JsonResponse
    {
        return $this->one(SettingsResources::holiday($this->service->saveHoliday($this->actor($request), null, $request->attributesToSave())), 201);
    }

    public function updateHoliday(SaveHolidayRequest $request, Holiday $holiday): JsonResponse
    {
        return $this->one(SettingsResources::holiday($this->service->saveHoliday($this->actor($request), $holiday, $request->attributesToSave())));
    }

    public function destroyHoliday(Request $request, Holiday $holiday): Response
    {
        $this->service->deleteHoliday($this->actor($request), $holiday);

        return response()->noContent();
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function list(array $rows): JsonResponse
    {
        return new JsonResponse(['data' => array_values($rows)]);
    }

    /** @param  array<string, mixed>  $row */
    private function one(array $row, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $row], $status);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
