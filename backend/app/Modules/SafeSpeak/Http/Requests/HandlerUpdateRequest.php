<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Http\Requests;

use App\Modules\SafeSpeak\Enums\ReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Handler inbox: ?status= filter, PATCH {status}, POST messages {body}. */
final class HandlerUpdateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return ['status' => ['nullable', Rule::enum(ReportStatus::class)]];
        }
        if ($this->isMethod('PATCH')) {
            return ['status' => ['required', Rule::enum(ReportStatus::class)]];
        }

        return ['body' => ['required', 'string', 'max:10000']];
    }

    public function status(): ?ReportStatus
    {
        return $this->enum('status', ReportStatus::class);
    }

    public function body(): string
    {
        return $this->string('body')->toString();
    }
}
