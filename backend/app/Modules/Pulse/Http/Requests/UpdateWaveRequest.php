<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/pulse/waves/{id} {min_group_size}: raise the minimum group of a scheduled/open wave. Admins. */
final class UpdateWaveRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['min_group_size' => ['required', 'integer', 'min:1', 'max:1000']];
    }

    public function minGroup(): int
    {
        return $this->integer('min_group_size');
    }
}
