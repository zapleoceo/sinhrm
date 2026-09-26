<?php

declare(strict_types=1);

namespace App\Modules\Assets\Http\Controllers;

use App\Models\User;
use App\Modules\Assets\Http\Requests\AssetMoveRequest;
use App\Modules\Assets\Http\Requests\ListAssetsRequest;
use App\Modules\Assets\Http\Requests\SaveAssetRequest;
use App\Modules\Assets\Http\Requests\SaveAssetTypeRequest;
use App\Modules\Assets\Http\Resources\AssetPresenter;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetType;
use App\Modules\Assets\Services\AssetService;
use App\Modules\People\Services\EmployeeService;
use App\Modules\People\Services\PeopleScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventory (gate assets-manage) and the employee's assets (profile tab: admin, the employee, managers above —
 * the People "job" tier; others 404).
 */
final class AssetController
{
    public function __construct(
        private readonly AssetService $assets,
        private readonly EmployeeService $employees,
        private readonly PeopleScope $scope,
    ) {}

    public function types(): JsonResponse
    {
        return new JsonResponse(['data' => $this->assets->types()->map(static fn (AssetType $t): array => AssetPresenter::type($t))->values()->all()]);
    }

    public function storeType(SaveAssetTypeRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => AssetPresenter::type($this->assets->saveType(null, ['name' => $request->name()]))], 201);
    }

    public function updateType(SaveAssetTypeRequest $request, int $type): JsonResponse
    {
        return new JsonResponse(['data' => AssetPresenter::type($this->assets->saveType($this->assets->findType($type), ['name' => $request->name()]))]);
    }

    public function index(ListAssetsRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->assets->list($request->filter())->map(static fn (Asset $a): array => AssetPresenter::asset($a))->values()->all()]);
    }

    public function show(int $asset): JsonResponse
    {
        return new JsonResponse(['data' => AssetPresenter::asset($this->assets->find($asset), true)]);
    }

    public function store(SaveAssetRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => AssetPresenter::asset($this->assets->save(null, $request->attributesToSave()), true)], 201);
    }

    public function update(SaveAssetRequest $request, int $asset): JsonResponse
    {
        return new JsonResponse(['data' => AssetPresenter::asset($this->assets->save($this->assets->find($asset), $request->attributesToSave()), true)]);
    }

    public function assign(AssetMoveRequest $request, int $asset): JsonResponse
    {
        $moved = $this->assets->assign($this->actor($request), $this->assets->find($asset), $this->employees->find($request->employeeId()), $request->movedOn(), $request->condition());

        return new JsonResponse(['data' => AssetPresenter::asset($moved, true)]);
    }

    public function return(AssetMoveRequest $request, int $asset): JsonResponse
    {
        $moved = $this->assets->return($this->actor($request), $this->assets->find($asset), $request->movedOn(), $request->condition(), $request->returnStatus());

        return new JsonResponse(['data' => AssetPresenter::asset($moved, true)]);
    }

    /** GET /api/assets/employee/{id}: current and past assets of one employee. */
    public function ofEmployee(Request $request, int $employee): JsonResponse
    {
        $ctx = $this->scope->for($this->actor($request));
        abort_unless($ctx->canSeeJob($employee), 404);

        return new JsonResponse(['data' => $this->assets->historyOf($this->employees->find($employee))
            ->map(static fn (AssetAssignment $h): array => AssetPresenter::assignment($h, true))->values()->all()]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
