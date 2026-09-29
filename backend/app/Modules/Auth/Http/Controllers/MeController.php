<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Http\Middleware\ApplyActiveRole;
use App\Modules\Auth\Http\Requests\UpdateActiveRoleRequest;
use App\Modules\Auth\Http\Requests\UpdateLocaleRequest;
use App\Modules\Auth\Http\Resources\MeResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class MeController
{
    public function show(Request $request): MeResource
    {
        return new MeResource($this->user($request));
    }

    public function updateLocale(UpdateLocaleRequest $request, AuthService $auth): MeResource
    {
        return new MeResource($auth->changeLocale($this->user($request), $request->locale()));
    }

    /** "Мій профіль": e-mails about approvals and decisions on/off. */
    public function updateNotifications(Request $request, AuthService $auth): MeResource
    {
        $data = $request->validate(['approval_emails' => ['required', 'boolean']]);

        return new MeResource($auth->changeApprovalEmails($this->user($request), (bool) $data['approval_emails']));
    }

    /**
     * "Працювати як": remember the chosen role (null = all roles) in this session only, so a stale choice never
     * follows the user to another device. Always allowed, whatever role is active — the way back is never closed.
     */
    public function updateActiveRole(UpdateActiveRoleRequest $request): MeResource
    {
        $role = $request->role();
        if ($request->hasSession()) {
            $role === null
                ? $request->session()->forget(ApplyActiveRole::SESSION_KEY)
                : $request->session()->put(ApplyActiveRole::SESSION_KEY, $role);
        }
        $user = $this->user($request);
        $user->actAs($role);

        return new MeResource($user);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
