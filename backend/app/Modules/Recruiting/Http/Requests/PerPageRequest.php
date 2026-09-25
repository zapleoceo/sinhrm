<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Only ?perPage=1..200 (string-safe) and ?page. */
final class PerPageRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['perPage' => ['nullable', 'integer', 'between:1,200']];
    }

    public function perPage(): int
    {
        return $this->integer('perPage', 50);
    }
}
