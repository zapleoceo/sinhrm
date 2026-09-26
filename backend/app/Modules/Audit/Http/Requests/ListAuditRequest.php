<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Requests;

use App\Modules\Audit\DTO\AuditFilter;
use App\Modules\Audit\Enums\AuditAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class ListAuditRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'min:1'],
            'entity_type' => ['nullable', 'string', 'max:48'],
            'action' => ['nullable', 'string', Rule::in(AuditAction::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function filter(): AuditFilter
    {
        $from = $this->string('from')->toString();
        $to = $this->string('to')->toString();

        return new AuditFilter(
            userId: $this->filled('user_id') ? $this->integer('user_id') : null,
            entityType: $this->filled('entity_type') ? $this->string('entity_type')->toString() : null,
            action: $this->filled('action') ? $this->string('action')->toString() : null,
            from: $from !== '' ? Carbon::createFromFormat('Y-m-d', $from)?->startOfDay() : null,
            // "to" is inclusive: everything before the next midnight.
            to: $to !== '' ? Carbon::createFromFormat('Y-m-d', $to)?->startOfDay()->addDay() : null,
            page: $this->integer('page', 1),
            perPage: $this->integer('perPage', 20),
        );
    }
}
