<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use App\Modules\People\Models\Employee;
use App\Modules\Perform\Enums\FeedbackType;
use App\Modules\Perform\Enums\FeedbackVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/perform/feedback {to_employee_id (not for an answer), type, text, visibility?, request_id? (answer)}.
 * A request (type=request) is always private; an answer goes to the requester.
 */
final class GiveFeedbackRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_id' => ['nullable', 'integer', 'min:1'],
            'to_employee_id' => ['required_without:request_id', 'nullable', 'integer', Rule::exists(Employee::class, 'id')],
            'type' => ['required', Rule::enum(FeedbackType::class)],
            'text' => ['required', 'string', 'max:5000'],
            'visibility' => ['nullable', Rule::enum(FeedbackVisibility::class)],
        ];
    }

    /** @return array{to_employee_id?: int|null, type: string, text: string, visibility?: string|null, request_id?: int|null} */
    public function payload(): array
    {
        return [
            'to_employee_id' => $this->filled('to_employee_id') ? $this->integer('to_employee_id') : null,
            'type' => (string) $this->string('type'),
            'text' => (string) $this->string('text'),
            'visibility' => $this->filled('visibility') ? (string) $this->string('visibility') : null,
            'request_id' => $this->filled('request_id') ? $this->integer('request_id') : null,
        ];
    }
}
