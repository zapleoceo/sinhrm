<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use App\Modules\Perform\Enums\OneOnOneStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/perform/one-on-ones/{id}: any subset of scheduled_at, status, agenda [{id?, text, done}], notes_shared,
 * notes_private_manager (meeting manager only), action_items [{id?, text, done, due_on?}]. Permissions per field —
 * OneOnOneService::update.
 */
final class UpdateOneOnOneRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::enum(OneOnOneStatus::class)],
            'notes_shared' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'notes_private_manager' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'agenda' => ['sometimes', 'array', 'max:50'],
            'agenda.*.id' => ['nullable', 'string', 'max:32'],
            'agenda.*.text' => ['required', 'string', 'max:500'],
            'agenda.*.done' => ['sometimes', 'boolean'],
            'action_items' => ['sometimes', 'array', 'max:50'],
            'action_items.*.id' => ['nullable', 'string', 'max:32'],
            'action_items.*.text' => ['required', 'string', 'max:500'],
            'action_items.*.done' => ['sometimes', 'boolean'],
            'action_items.*.due_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();
        foreach (['agenda', 'action_items'] as $list) {
            if (isset($data[$list]) && is_array($data[$list])) {
                $data[$list] = array_map(static fn (array $item): array => ['done' => (bool) ($item['done'] ?? false)] + $item, $data[$list]);
            }
        }

        return $data;
    }
}
