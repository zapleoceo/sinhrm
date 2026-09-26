<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Resources;

use App\Models\User;
use App\Modules\Core\Services\ModuleAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class MeResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'locale' => $this->locale,
            'roles' => $this->getRoleNames()->values()->all(),
            'status' => $this->status->value,
            // Modules this user may open (switched on + role allowed); the SPA hides the rest (modules-access.md).
            'modules' => app(ModuleAccess::class)->allowedKeys($this->resource),
        ];
    }
}
