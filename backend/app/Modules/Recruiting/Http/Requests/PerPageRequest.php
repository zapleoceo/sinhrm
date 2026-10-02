<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\Paginates;
use Illuminate\Foundation\Http\FormRequest;

/** Only ?perPage=1..200 (string-safe) and ?page. */
final class PerPageRequest extends FormRequest
{
    use Paginates;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['perPage' => $this->perPageRules()];
    }

    public function perPage(): int
    {
        return $this->perPageOr();
    }
}
