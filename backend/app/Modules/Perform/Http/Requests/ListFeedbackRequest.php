<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use App\Modules\Perform\Services\FeedbackService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/perform/feedback?box=received|given|requests|team|public (default received). */
final class ListFeedbackRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'box' => ['nullable', 'string', Rule::in(FeedbackService::BOXES)],
        ];
    }

    public function box(): string
    {
        return $this->filled('box') ? (string) $this->string('box') : 'received';
    }
}
