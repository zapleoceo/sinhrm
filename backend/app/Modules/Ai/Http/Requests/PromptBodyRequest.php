<?php

declare(strict_types=1);

namespace App\Modules\Ai\Http\Requests;

use App\Modules\Ai\Support\PromptOverrides;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Edited instruction text (save or try): length, ROLE/TASK/RULES, no OUTPUT, no dates/ids — errors are codes. */
final class PromptBodyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.(PromptOverrides::MAX_LENGTH * 2), static function (string $attribute, mixed $value, Closure $fail): void {
                foreach (PromptOverrides::problems((string) $value) as $code) {
                    $fail($code);
                }
            }],
        ];
    }

    public function body(): string
    {
        return PromptOverrides::normalize($this->string('body')->toString());
    }
}
