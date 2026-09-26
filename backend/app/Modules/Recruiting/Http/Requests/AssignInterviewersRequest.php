<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /applications/{application}/interviewers {user_ids: int[]} — an empty list removes everyone. */
final class AssignInterviewersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('assignInterviewers', $this->route('application'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_ids' => ['present', 'array', 'max:20'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists(User::class, 'id')->where('status', 'active')],
        ];
    }

    /** @return list<int> */
    public function userIds(): array
    {
        $ids = $this->input('user_ids', []);

        return array_values(array_map(static fn (mixed $id): int => (int) $id, is_array($ids) ? $ids : []));
    }
}
