<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests\Concerns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ?perPage of a paginated list: one rule and one cast for every FormRequest. The query string always carries text
 * ("20"), so the value is read with integer() — never trusted as an int — after the rule kept it within 1..$max.
 *
 * @phpstan-require-extends FormRequest
 */
trait Paginates
{
    /** @return list<string> the validation rule of perPage: optional, integer, 1..$max */
    protected function perPageRules(int $max = 200): array
    {
        return ['nullable', 'integer', 'between:1,'.$max];
    }

    /** The validated ?perPage as an int ("20" → 20), $default when it is absent. */
    protected function perPageOr(int $default = 50): int
    {
        return $this->integer('perPage', $default);
    }
}
