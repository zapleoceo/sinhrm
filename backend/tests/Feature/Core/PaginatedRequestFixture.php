<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Core\Http\Requests\Concerns\Paginates;
use Illuminate\Foundation\Http\FormRequest;

/** A list request built on Paginates with a configurable maximum and default (PaginatesTest). */
final class PaginatedRequestFixture extends FormRequest
{
    use Paginates;

    public int $max = 200;

    public int $default = 50;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['perPage' => $this->perPageRules($this->max)];
    }

    public function perPage(): int
    {
        return $this->perPageOr($this->default);
    }
}
