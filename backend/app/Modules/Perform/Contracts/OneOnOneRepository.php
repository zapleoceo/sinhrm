<?php

declare(strict_types=1);

namespace App\Modules\Perform\Contracts;

use App\Modules\Perform\Models\OneOnOne;
use App\Modules\Perform\Models\OneOnOneTemplate;
use Illuminate\Database\Eloquent\Collection;

interface OneOnOneRepository
{
    /**
     * Meetings by scheduled_at desc, with manager and employee.
     *
     * @param  list<int>|null  $visibleIds  null = all; otherwise meetings of these employees or run by $selfId
     * @return Collection<int, OneOnOne>
     */
    public function list(?array $visibleIds, ?int $selfId, ?int $employeeId, ?string $status, int $limit): Collection;

    public function find(int $id): ?OneOnOne;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): OneOnOne;

    /** @param  array<string, mixed>  $attributes */
    public function update(OneOnOne $meeting, array $attributes): OneOnOne;

    public function delete(OneOnOne $meeting): void;

    /** @return Collection<int, OneOnOneTemplate> */
    public function templates(): Collection;

    public function findTemplate(int $id): ?OneOnOneTemplate;

    /** @param  array<string, mixed>  $attributes */
    public function saveTemplate(?OneOnOneTemplate $template, array $attributes): OneOnOneTemplate;

    public function deleteTemplate(OneOnOneTemplate $template): void;
}
