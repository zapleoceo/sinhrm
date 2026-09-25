<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH /api/tasks/{task} {done: bool}. Viewers and users who cannot see the task → 403 (TaskPolicy::update). */
final class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('task'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['done' => ['required', 'boolean']];
    }

    public function done(): bool
    {
        return $this->boolean('done');
    }
}
