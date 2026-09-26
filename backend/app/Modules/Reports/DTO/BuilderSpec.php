<?php

declare(strict_types=1);

namespace App\Modules\Reports\DTO;

/**
 * A validated builder request. Column keys are already checked against the dataset whitelist (BuilderService);
 * values stay values (bound as parameters), never SQL.
 */
final readonly class BuilderSpec
{
    public const array OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains'];

    public const array AGGREGATES = ['count', 'sum', 'avg'];

    /**
     * @param  list<string>  $columns
     * @param  list<array{column: string, op: string, value: string|int|float|null}>  $filters
     */
    public function __construct(
        public string $dataset,
        public array $columns,
        public array $filters,
        public ?string $groupBy,
        public ?string $aggregate,
        public ?string $aggregateColumn,
    ) {}

    /** @return array<string, mixed> for saving and echoing back */
    public function toArray(): array
    {
        return [
            'dataset' => $this->dataset,
            'columns' => $this->columns,
            'filters' => $this->filters,
            'group_by' => $this->groupBy,
            'aggregate' => $this->aggregate === null ? null : ['fn' => $this->aggregate, 'column' => $this->aggregateColumn],
        ];
    }
}
