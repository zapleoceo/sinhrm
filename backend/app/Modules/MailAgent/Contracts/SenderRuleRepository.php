<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Contracts;

use App\Modules\MailAgent\Models\SenderRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

interface SenderRuleRepository
{
    /** @return Collection<int, SenderRule> by pattern */
    public function all(): Collection;

    public function find(int $id): ?SenderRule;

    public function findByPattern(string $pattern): ?SenderRule;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): SenderRule;

    /** @param  array<string, mixed>  $attributes */
    public function update(SenderRule $rule, array $attributes): SenderRule;

    public function delete(SenderRule $rule): void;

    /** hits + 1, last_seen_at = $at (atomic increment). */
    public function hit(int $id, Carbon $at): void;

    public function count(): int;
}
