<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use App\Modules\Reports\Contracts\Dataset;
use App\Modules\Reports\Contracts\ReportDefinition;
use LogicException;

/** Catalog reports and builder datasets tagged in ReportsServiceProvider, by key (keys must be unique). */
final class ReportRegistry
{
    /** @var array<string, ReportDefinition> */
    private array $reports = [];

    /** @var array<string, Dataset> */
    private array $datasets = [];

    /**
     * @param  iterable<ReportDefinition>  $reports
     * @param  iterable<Dataset>  $datasets
     */
    public function __construct(iterable $reports, iterable $datasets)
    {
        foreach ($reports as $r) {
            if (isset($this->reports[$r->key()])) {
                throw new LogicException('Duplicate report key '.$r->key());
            }
            $this->reports[$r->key()] = $r;
        }
        foreach ($datasets as $d) {
            $this->datasets[$d->key()] = $d;
        }
    }

    /** @return list<ReportDefinition> */
    public function reports(): array
    {
        return array_values($this->reports);
    }

    public function report(string $key): ?ReportDefinition
    {
        return $this->reports[$key] ?? null;
    }

    /** @return list<Dataset> */
    public function datasets(): array
    {
        return array_values($this->datasets);
    }

    public function dataset(string $key): ?Dataset
    {
        return $this->datasets[$key] ?? null;
    }
}
