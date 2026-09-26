<?php

declare(strict_types=1);

namespace App\Modules\Documents\Repositories;

use App\Modules\Documents\Contracts\DocumentTemplateRepository;
use App\Modules\Documents\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentDocumentTemplateRepository implements DocumentTemplateRepository
{
    public function list(bool $withArchived): Collection
    {
        return DocumentTemplate::query()
            ->when(! $withArchived, fn (Builder $q) => $q->where('archived', false))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function find(int $id): ?DocumentTemplate
    {
        return DocumentTemplate::query()->find($id);
    }

    public function create(array $attributes): DocumentTemplate
    {
        return DocumentTemplate::query()->create($attributes);
    }

    public function update(DocumentTemplate $template, array $attributes): DocumentTemplate
    {
        $template->fill($attributes)->save();

        return $template;
    }
}
