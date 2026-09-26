<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Contracts;

use App\Modules\SafeSpeak\Models\SafeSpeakReport;
use Illuminate\Database\Eloquent\Collection;

interface SafeSpeakRepository
{
    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes, string $body): SafeSpeakReport;

    public function findByCodeHash(string $hash): ?SafeSpeakReport;

    public function find(int $id): ?SafeSpeakReport;

    /** @return Collection<int, SafeSpeakReport> newest first, with message counts */
    public function inbox(?string $status, int $limit): Collection;

    public function addMessage(SafeSpeakReport $report, string $author, ?int $handlerId, string $body, string $today): void;

    /** @param  array<string, mixed>  $attributes */
    public function update(SafeSpeakReport $report, array $attributes): SafeSpeakReport;
}
