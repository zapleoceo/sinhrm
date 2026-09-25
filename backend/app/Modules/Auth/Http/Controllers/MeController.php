<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
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
