<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Resources;

use App\Models\User;
use App\Modules\Directory\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'roles' => $this->getRoleNames()->values()->all(),
            'status' => $this->status->value,
            // Branch scope (Directory module); superadmin/admin are not limited by it.
            'branches' => $this->branches
                ->sortBy('name')
                ->map(static fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name, 'status' => $b->status->value])
                ->values()
                ->all(),
            'safe_speak_handler' => (bool) $this->safe_speak_handler,
            'locale' => $this->locale,
            'invited_by' => $this->invited_by,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
