<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\DTO\MoveData;
use App\Modules\Recruiting\Models\RejectReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /applications/{application}/move {stage_id, reason?, reject_reason_id?} */
final class MoveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('move', $this->route('application'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'stage_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'reject_reason_id' => ['nullable', 'integer', Rule::exists(RejectReason::class, 'id')->where('active', true)],
        ];
    }

    public function moveData(): MoveData
    {
        return new MoveData(
            stageId: $this->integer('stage_id'),
            reason: $this->filled('reason') ? $this->string('reason')->trim()->toString() : null,
            rejectReasonId: $this->filled('reject_reason_id') ? $this->integer('reject_reason_id') : null,
        );
    }
}
