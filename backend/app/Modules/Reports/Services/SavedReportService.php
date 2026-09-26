<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Contracts\SavedReportRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Models\SavedReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Saved reports of one user. Definitions are validated when saved (builder: the whitelist; catalog: a report the
 * user may run) and again when run — under the user's scope at that moment.
 */
final readonly class SavedReportService
{
    public const int MAX_PER_USER = 100;

    public function __construct(
        private SavedReportRepository $saved,
        private BuilderService $builder,
        private ReportCatalogService $catalog,
    ) {}

    /** @return Collection<int, SavedReport> */
    public function list(ScopedContext $ctx): Collection
    {
        return $this->saved->ofUser($ctx->user->id);
    }

    public function find(ScopedContext $ctx, int $id): SavedReport
    {
        return $this->saved->findOwn($ctx->user->id, $id) ?? abort(404);
    }

    /** @param  array<string, mixed>  $definition */
    public function save(ScopedContext $ctx, ?SavedReport $report, string $name, string $kind, array $definition): SavedReport
    {
        if ($report === null && $this->saved->ofUser($ctx->user->id)->count() >= self::MAX_PER_USER) {
            throw ValidationException::withMessages(['name' => 'too_many_saved_reports']);
        }

        return $this->saved->save($report, [
            'user_id' => $ctx->user->id,
            'name' => $name,
            'kind' => $kind,
            'definition' => $this->normalize($ctx, $kind, $definition),
        ]);
    }

    public function delete(SavedReport $report): void
    {
        $this->saved->delete($report);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function normalize(ScopedContext $ctx, string $kind, array $definition): array
    {
        if ($kind === SavedReport::BUILDER) {
            return $this->builder->spec($ctx, $definition)->toArray();
        }
        $key = (string) ($definition['key'] ?? '');
        $report = $this->catalog->find($ctx, $key);
        $filters = array_intersect_key((array) ($definition['filters'] ?? []), array_flip($report->filters()));

        return ['key' => $report->key(), 'filters' => array_map(static fn (mixed $v): ?string => is_scalar($v) ? (string) $v : null, $filters)];
    }
}
