<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /recruiting/assignable-users?q= — access is checked in HiringTeamService (writers and hiring managers). */
final class AssignableUsersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100']];
    }

    public function term(): ?string
    {
        $q = trim($this->string('q')->toString());

        return $q === '' ? null : $q;
    }
}
