<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Models\User;
use App\Modules\Recruiting\Models\RejectReason;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /candidates/bulk {action: move|reject|tag|assign, ids[≤200], vacancy_id?, stage_id?, reject_reason_id?, reason?, tag?, owner_id?}.
 * Per-candidate permissions are checked item by item (the same policy as the single action).
 */
final class BulkCandidatesRequest extends FormRequest
{
    public const int MAX = 200;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['move', 'reject', 'tag', 'assign'])],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['integer', 'min:1', 'distinct'],
            'vacancy_id' => ['required_if:action,move,reject', 'nullable', 'integer', Rule::exists(Vacancy::class, 'id')],
            'stage_id' => ['required_if:action,move', 'nullable', 'integer', 'min:1'],
            'reject_reason_id' => ['required_if:action,reject', 'nullable', 'integer', Rule::exists(RejectReason::class, 'id')->where('active', true)],
            'reason' => ['nullable', 'string', 'max:2000'],
            'tag' => ['required_if:action,tag', 'nullable', 'string', 'min:1', 'max:40'],
            'owner_id' => ['required_if:action,assign', 'nullable', 'integer', Rule::exists(User::class, 'id')],
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_map('intval', (array) $this->validated('ids')));
    }
}
