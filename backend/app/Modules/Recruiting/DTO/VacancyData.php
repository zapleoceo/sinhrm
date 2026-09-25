<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

/** Validated vacancy fields: only the keys that were sent (PATCH = partial). */
final readonly class VacancyData
{
    /** @param  array<string, mixed>  $attributes */
    public function __construct(public array $attributes) {}
}
