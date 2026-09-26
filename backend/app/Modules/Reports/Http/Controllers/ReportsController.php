<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Models\User;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Http\Requests\BuilderRequest;
use App\Modules\Reports\Http\Requests\SaveReportRequest;
use App\Modules\Reports\Http\Resources\CsvResponse;
use App\Modules\Reports\Models\SavedReport;
use App\Modules\Reports\Services\BuilderService;
use App\Modules\Reports\Services\ReportCatalogService;
use App\Modules\Reports\Services\SavedReportService;
use App\Modules\Reports\Services\ScopedContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report catalog, custom builder, saved reports, CSV export. Every call is scoped by ScopedContext (People and
 * Recruiting access); reports and datasets the user may not use answer 404 / 422.
 */
final class ReportsController
{
    public function __construct(
        private readonly ScopedContextFactory $contexts,
        private readonly ReportCatalogService $catalog,
        private readonly BuilderService $builder,
        private readonly SavedReportService $saved,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->catalog->catalog($this->ctx($request))]);
    }

    public function run(Request $request, string $key): JsonResponse
    {
        $ctx = $this->ctx($request);

        return new JsonResponse(['data' => $this->catalog->run($ctx, $this->catalog->find($ctx, $key), $request->query())]);
    }

    public function csv(Request $request, string $key): StreamedResponse
    {
        $ctx = $this->ctx($request);
        $result = $this->catalog->run($ctx, $this->catalog->find($ctx, $key), $request->query());

        return CsvResponse::make($key, array_column($result['report']['columns'], 'key'), $result['rows']);
    }

    public function datasets(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->builder->datasets($this->ctx($request))]);
    }

    public function build(BuilderRequest $request): JsonResponse
    {
        $ctx = $this->ctx($request);
        $spec = $this->builder->spec($ctx, $request->spec());

        return new JsonResponse(['data' => ['spec' => $spec->toArray()] + $this->builder->run($ctx, $spec)]);
    }

    public function buildCsv(BuilderRequest $request): StreamedResponse
    {
        $ctx = $this->ctx($request);
        $spec = $this->builder->spec($ctx, $request->spec());
        $result = $this->builder->run($ctx, $spec);

        return CsvResponse::make($spec->dataset, $result['columns'], $result['rows']);
    }

    public function saved(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->saved->list($this->ctx($request))->map(static fn (SavedReport $r): array => self::savedJson($r))->values()->all()]);
    }

    public function store(SaveReportRequest $request): JsonResponse
    {
        $saved = $this->saved->save($this->ctx($request), null, $request->name(), $request->kind(), $request->definition());

        return new JsonResponse(['data' => self::savedJson($saved)], 201);
    }

    public function update(SaveReportRequest $request, int $id): JsonResponse
    {
        $ctx = $this->ctx($request);
        $saved = $this->saved->save($ctx, $this->saved->find($ctx, $id), $request->name(), $request->kind(), $request->definition());

        return new JsonResponse(['data' => self::savedJson($saved)]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $ctx = $this->ctx($request);
        $this->saved->delete($this->saved->find($ctx, $id));

        return new Response('', 204);
    }

    /** Runs a saved report under the owner's current scope (as JSON or CSV with ?format=csv). */
    public function runSaved(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $ctx = $this->ctx($request);
        $saved = $this->saved->find($ctx, $id);
        $csv = $request->query('format') === 'csv';
        if ($saved->kind === SavedReport::BUILDER) {
            $spec = $this->builder->spec($ctx, $saved->definition);
            $result = $this->builder->run($ctx, $spec);

            return $csv ? CsvResponse::make($saved->name, $result['columns'], $result['rows'])
                : new JsonResponse(['data' => ['spec' => $spec->toArray()] + $result]);
        }
        /** @var array<string, mixed> $filters */
        $filters = (array) ($saved->definition['filters'] ?? []);
        $result = $this->catalog->run($ctx, $this->catalog->find($ctx, (string) ($saved->definition['key'] ?? '')), $filters);

        return $csv ? CsvResponse::make($saved->name, array_column($result['report']['columns'], 'key'), $result['rows'])
            : new JsonResponse(['data' => $result]);
    }

    /** @return array<string, mixed> */
    private static function savedJson(SavedReport $r): array
    {
        return ['id' => $r->id, 'name' => $r->name, 'kind' => $r->kind, 'definition' => $r->definition, 'updated_at' => $r->updated_at?->toIso8601String()];
    }

    private function ctx(Request $request): ScopedContext
    {
        $user = $request->user();
        assert($user instanceof User);

        return $this->contexts->for($user);
    }
}
