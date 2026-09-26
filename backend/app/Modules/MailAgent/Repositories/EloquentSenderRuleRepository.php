<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Repositories;

use App\Modules\MailAgent\Contracts\SenderRuleRepository;
use App\Modules\MailAgent\Models\SenderRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class EloquentSenderRuleRepository implements SenderRuleRepository
{
    public function all(?string $source = null): Collection
    {
        return SenderRule::query()->when($source !== null, fn ($q) => $q->where('source', $source))->orderBy('pattern')->get();
    }

    public function find(int $id): ?SenderRule
    {
        return SenderRule::query()->find($id);
    }

    public function findByPattern(string $pattern): ?SenderRule
    {
        return SenderRule::query()->where('pattern', $pattern)->first();
    }

    public function create(array $attributes): SenderRule
    {
        return SenderRule::query()->create($attributes);
    }

    public function update(SenderRule $rule, array $attributes): SenderRule
    {
        $rule->fill($attributes)->save();

        return $rule;
    }

    public function delete(SenderRule $rule): void
    {
        $rule->delete();
    }

    public function hit(int $id, Carbon $at): void
    {
        SenderRule::query()->whereKey($id)->increment('hits', 1, ['last_seen_at' => $at]);
    }

    public function count(): int
    {
        return SenderRule::query()->count();
    }
}
