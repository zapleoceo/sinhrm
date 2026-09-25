<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Repositories;

use App\Modules\Scripts\Contracts\ScriptRepository;
use App\Modules\Scripts\Enums\ScriptChannel;
use App\Modules\Scripts\Models\Script;
use App\Modules\Scripts\Models\ScriptVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentScriptRepository implements ScriptRepository
{
    public function list(bool $withArchived): Collection
    {
        return Script::query()
            ->with(['activeVersion', 'draft'])
            ->when(! $withArchived, fn (Builder $q) => $q->where('archived', false))
            ->orderBy('archived')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function find(int $id): ?Script
    {
        return Script::query()->with(['activeVersion.author', 'draft.author'])->find($id);
    }

    public function create(array $attributes): Script
    {
        return Script::query()->create($attributes);
    }

    public function update(Script $script, array $attributes): Script
    {
        $script->fill($attributes)->save();

        return $script;
    }

    public function createVersion(array $attributes): ScriptVersion
    {
        return ScriptVersion::query()->create($attributes);
    }

    public function updateDraft(ScriptVersion $draft, array $attributes): ScriptVersion
    {
        if (! $draft->isDraft()) {
            throw new LogicException('A published script version is immutable.');
        }
        $draft->fill($attributes)->save();

        return $draft;
    }

    public function nextVersionNumber(int $scriptId): int
    {
        return (int) ScriptVersion::query()->where('script_id', $scriptId)->max('version') + 1;
    }

    public function draftForUpdate(int $scriptId): ?ScriptVersion
    {
        return ScriptVersion::query()->where('script_id', $scriptId)->whereNull('published_at')->lockForUpdate()->first();
    }

    public function findVersion(int $scriptId, int $version): ?ScriptVersion
    {
        return ScriptVersion::query()->where('script_id', $scriptId)->where('version', $version)->first();
    }

    public function versions(int $scriptId): Collection
    {
        return ScriptVersion::query()->with('author')->where('script_id', $scriptId)->orderByDesc('version')->get();
    }

    public function activeFor(ScriptChannel $channel): ?Script
    {
        return $this->activeQuery()->where('channel', $channel->value)->orderBy('id')->first();
    }

    public function active(): Collection
    {
        return $this->activeQuery()->orderBy('id')->get();
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }

    /** @return Builder<Script> */
    private function activeQuery(): Builder
    {
        return Script::query()->with('activeVersion')->where('archived', false)->whereNotNull('active_version_id');
    }
}
