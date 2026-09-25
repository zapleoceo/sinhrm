<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Models\User;
use App\Modules\Users\Http\Requests\InviteUserRequest;
use App\Modules\Users\Http\Requests\ListUsersRequest;
use App\Modules\Users\Http\Requests\UpdateUserRequest;
use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Users\Services\UserAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Users admin. Access: Gate "manage-users" (routes.php). */
final class UsersController
{
    public function __construct(private readonly UserAdminService $service) {}

    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->service->list($request->filter()));
    }

    public function store(InviteUserRequest $request): JsonResponse
    {
        $user = $this->service->invite($this->actor($request), $request->email(), $request->name(), $request->role());

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        return new UserResource($this->service->update(
            $this->actor($request),
            $user,
            $request->role(),
            $request->status(),
            $request->branchIds(),
        ));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
