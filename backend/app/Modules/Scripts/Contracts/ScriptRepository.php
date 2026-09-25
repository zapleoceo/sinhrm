<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Contracts;

use App\Modules\Scripts\Enums\ScriptChannel;
use App\Modules\Scripts\Models\Script;
use App\Modules\Scripts\Models\ScriptVersion;
use Illuminate\Database\Eloquent\Collection;

interface ScriptRepository
{
    /** @return Collection<int, Script> with activeVersion and draft, newest first */
    public function list(bool $withArchived): Collection;

    public function find(int $id): ?Script;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Script;

    /** @param  array<string, mixed>  $attributes */
    public function update(Script $script, array $attributes): Script;

    /** @param  array<string, mixed>  $attributes */
    public function createVersion(array $attributes): ScriptVersion;

    /**
     * Only drafts may change (published versions are immutable).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDraft(ScriptVersion $draft, array $attributes): ScriptVersion;

    public function nextVersionNumber(int $scriptId): int;

    /** The draft row locked for the current transaction (null = no draft). */
    public function draftForUpdate(int $scriptId): ?ScriptVersion;

    public function findVersion(int $scriptId, int $version): ?ScriptVersion;

    /** @return Collection<int, ScriptVersion> newest first, with author */
    public function versions(int $scriptId): Collection;

    /** The script used for evaluation of this channel: the oldest non-archived one with an active version. */
    public function activeFor(ScriptChannel $channel): ?Script;

    /** @return Collection<int, Script> non-archived scripts with an active version (activeVersion loaded) */
    public function active(): Collection;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
