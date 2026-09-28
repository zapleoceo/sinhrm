<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Requests;

use App\Modules\Assistant\Ai\QuipsPrompt;
use Illuminate\Foundation\Http\FormRequest;

/** GET /api/assistant/quips?situation=fall|thrown|slip&locale=uk|ru|en */
final class QuipsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'situation' => ['required', 'string', 'in:'.implode(',', array_keys(QuipsPrompt::SITUATIONS))],
            'locale' => ['required', 'string', 'in:'.implode(',', array_keys(QuipsPrompt::LANGUAGES))],
        ];
    }

    public function situation(): string
    {
        return (string) $this->validated('situation');
    }

    public function locale(): string
    {
        return (string) $this->validated('locale');
    }
}
