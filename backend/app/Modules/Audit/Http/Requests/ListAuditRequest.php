<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Requests;

use App\Modules\Audit\DTO\AuditFilter;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditSort;
use App\Modules\Core\Http\Requests\Concerns\Paginates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class ListAuditRequest extends FormRequest
{
    use Paginates;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'min:1'],
            'entity_type' => ['nullable', 'string', 'max:48'],
            'action' => ['nullable', 'string', Rule::in(AuditAction::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // sort/dir: closed lists (unknown column or direction → 422). Without sort: newest first.
            'sort' => ['nullable', Rule::enum(AuditSort::class)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => $this->perPageRules(100),
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
            perPage: $this->perPageOr(20),
            sort: $this->enum('sort', AuditSort::class) ?? AuditSort::Time,
            // The log reads newest first: no sort, or a sort without dir, is descending by time; dir=asc flips it.
            descending: $this->filled('sort') ? $this->input('dir') === 'desc' : $this->input('dir') !== 'asc',
        );
    }
}
