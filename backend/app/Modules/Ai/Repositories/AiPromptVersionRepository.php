<?php

declare(strict_types=1);

namespace App\Modules\Ai\Repositories;

use App\Modules\Ai\Enums\AiPurpose;
use App\Modules\Ai\Models\AiPromptVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** ai_prompt_versions: at most one active row per purpose (switched inside a transaction). */
final class AiPromptVersionRepository
{
    public function active(AiPurpose $purpose): ?AiPromptVersion
    {
        return AiPromptVersion::query()->where('purpose', $purpose->value)->where('is_active', true)->orderByDesc('id')->first();
    }

    public function find(AiPurpose $purpose, int $id): ?AiPromptVersion
    {
        return AiPromptVersion::query()->where('purpose', $purpose->value)->whereKey($id)->first();
    }

    /** @return Collection<int, AiPromptVersion> newest first */
    public function list(AiPurpose $purpose, int $limit = 50): Collection
    {
        return AiPromptVersion::query()->where('purpose', $purpose->value)->orderByDesc('id')->limit($limit)->get();
    }

    /** Next free label: <built-in version>-custom-<n>. */
    public function nextVersion(AiPurpose $purpose, string $baseVersion): string
    {
        $n = AiPromptVersion::query()->where('purpose', $purpose->value)->count() + 1;
        while (AiPromptVersion::query()->where('version', $baseVersion.'-custom-'.$n)->exists()) {
            $n++;
        }

        return $baseVersion.'-custom-'.$n;
    }

    public function create(AiPurpose $purpose, string $version, string $baseVersion, string $body, int $authorId): AiPromptVersion
    {
        return AiPromptVersion::query()->create([
            'purpose' => $purpose->value,
            'version' => $version,
            'base_version' => $baseVersion,
            'body' => $body,
            'author_id' => $authorId,
        ]);
    }

    /** Makes $version the only active row of its purpose; null = none active (the built-in version is used). */
    public function activate(AiPurpose $purpose, ?AiPromptVersion $version, int $actorId): void
    {
        DB::transaction(function () use ($purpose, $version, $actorId): void {
            AiPromptVersion::query()->where('purpose', $purpose->value)->where('is_active', true)->update(['is_active' => false]);
            if ($version !== null) {
                $version->is_active = true;
                $version->activated_by = $actorId;
                $version->activated_at = Carbon::now();
                $version->save();
            }
        });
    }
}
