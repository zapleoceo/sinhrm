<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Services;

use App\Models\User;
use App\Modules\Scripts\Contracts\ScriptRepository;
use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\ScriptChannel;
use App\Modules\Scripts\Exceptions\ScriptException;
use App\Modules\Scripts\Models\Script;
use App\Modules\Scripts\Models\ScriptVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Scripts and their versions. Editing always goes to the single draft of a script; publishing freezes the draft
 * as a new immutable version and makes it active; activating an older published version is a rollback.
 */
final readonly class ScriptService
{
    public function __construct(private ScriptRepository $scripts) {}

    /** @return Collection<int, Script> */
    public function list(bool $withArchived): Collection
    {
        return $this->scripts->list($withArchived);
    }

    public function find(int $id): ?Script
    {
        return $this->scripts->find($id);
    }

    /** A new script starts with draft version 1 (nothing is active until the first publish). */
    public function create(User $actor, string $name, ScriptChannel $channel, ScriptContent $content): Script
    {
        return $this->scripts->transaction(function () use ($actor, $name, $channel, $content): Script {
            $script = $this->scripts->create(['name' => $name, 'channel' => $channel->value]);
            $this->scripts->createVersion(['script_id' => $script->id, 'version' => 1, 'author_id' => $actor->id] + $content->toArray());

            return $script;
        });
    }

    /** Rename / archive / restore. An archived script is neither evaluated nor offered as templates. */
    public function update(Script $script, ?string $name, ?bool $archived): Script
    {
        $attributes = array_filter(['name' => $name, 'archived' => $archived], static fn (mixed $v): bool => $v !== null);

        return $attributes === [] ? $script : $this->scripts->update($script, $attributes);
    }

    /** Creates the draft (next version number) or rewrites the existing one. */
    public function saveDraft(User $actor, Script $script, ScriptContent $content): ScriptVersion
    {
        if ($script->archived) {
            throw ScriptException::archived();
        }

        return $this->scripts->transaction(function () use ($actor, $script, $content): ScriptVersion {
            $attributes = ['author_id' => $actor->id] + $content->toArray();
            $draft = $this->scripts->draftForUpdate($script->id);
            if ($draft !== null) {
                return $this->scripts->updateDraft($draft, $attributes);
            }

            return $this->scripts->createVersion(
                ['script_id' => $script->id, 'version' => $this->scripts->nextVersionNumber($script->id)] + $attributes,
            );
        });
    }

    /** Freezes the draft (published_at = now) and makes it the active version. */
    public function publish(User $actor, Script $script, ?Carbon $at = null): ScriptVersion
    {
        if ($script->archived) {
            throw ScriptException::archived();
        }

        return $this->scripts->transaction(function () use ($actor, $script, $at): ScriptVersion {
            $draft = $this->scripts->draftForUpdate($script->id);
            if ($draft === null) {
                throw ScriptException::noDraft();
            }
            $version = $this->scripts->updateDraft($draft, ['published_at' => $at ?? Carbon::now(), 'author_id' => $actor->id]);
            $this->scripts->update($script, ['active_version_id' => $version->id]);

            return $version;
        });
    }

    /** Rollback (or forward) to any published version. */
    public function activate(Script $script, int $version): ScriptVersion
    {
        $target = $this->scripts->findVersion($script->id, $version);
        if ($target === null || $target->isDraft()) {
            throw ScriptException::versionNotPublished($version);
        }
        $this->scripts->update($script, ['active_version_id' => $target->id]);

        return $target;
    }

    /** @return Collection<int, ScriptVersion> */
    public function versions(Script $script): Collection
    {
        return $this->scripts->versions($script->id);
    }

    /** What the "test on text" panel checks: the draft (what is being edited) unless the active version is asked for. */
    public function contentForTest(Script $script, bool $preferActive): ScriptContent
    {
        $script->loadMissing(['draft', 'activeVersion']);
        $version = $preferActive ? ($script->activeVersion ?? $script->draft) : ($script->draft ?? $script->activeVersion);
        if ($version === null) {
            throw ScriptException::nothingToEvaluate();
        }

        return $version->content();
    }
}
