<?php

declare(strict_types=1);

namespace App\Modules\Documents\Contracts;

use App\Modules\Documents\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Collection;

interface DocumentTemplateRepository
{
    /** @return Collection<int, DocumentTemplate> by name */
    public function list(bool $withArchived): Collection;

    public function find(int $id): ?DocumentTemplate;

    /**
     * Not archived templates of a category by name, only id and name loaded (pickers of other modules).
     *
     * @return Collection<int, DocumentTemplate>
     */
    public function activeOfCategory(string $category): Collection;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): DocumentTemplate;

    /** @param  array<string, mixed>  $attributes */
    public function update(DocumentTemplate $template, array $attributes): DocumentTemplate;
}
