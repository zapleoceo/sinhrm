<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Enums\AppLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLocaleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['locale' => ['required', 'string', Rule::enum(AppLocale::class)]];
    }

    public function locale(): AppLocale
    {
        return AppLocale::from($this->string('locale')->toString());
    }
}
