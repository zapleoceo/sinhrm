<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\Enums\OfferStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /applications/{application}/offer {template_id, position, salary, start_date?, conditions?};
 * POST .../offer/send; POST .../offer/decision {status: accepted|declined}. Only whoever may see the offer.
 */
final class OfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('offer', $this->route('application'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->routeIs('recruiting.applications.offer.decision')) {
            return ['status' => ['required', Rule::in([OfferStatus::Accepted->value, OfferStatus::Declined->value])]];
        }
        if ($this->routeIs('recruiting.applications.offer.send')) {
            return [];
        }

        return [
            'template_id' => ['required', 'integer', 'min:1'],
            'position' => ['required', 'string', 'max:255'],
            'salary' => ['required', 'string', 'max:100'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'conditions' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array{template_id: int, position: string, salary: string, start_date: ?string, conditions: ?string} */
    public function offerData(): array
    {
        return [
            'template_id' => $this->integer('template_id'),
            'position' => $this->string('position')->trim()->toString(),
            'salary' => $this->string('salary')->trim()->toString(),
            'start_date' => $this->filled('start_date') ? $this->string('start_date')->toString() : null,
            'conditions' => $this->filled('conditions') ? $this->string('conditions')->trim()->toString() : null,
        ];
    }
}
