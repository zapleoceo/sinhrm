<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Ai;

/**
 * Everything the screening prompt may use, as plain data (built from the DB by ScreeningPromptFactory, or from a fixture
 * by the experiment harness). $names are used ONLY to remove the name from the materials, never sent.
 */
final readonly class ScreeningInput
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $names
     * @param  list<array{channel: string, body: string}>  $materials  newest first
     */
    public function __construct(
        public string $vacancyTitle,
        public ?string $position,
        public ?string $department,
        public ?string $requirements,
        public ?string $city,
        public array $tags,
        public array $names,
        public array $materials,
    ) {}

    /** @param  array<string, mixed>  $input  fixture: {vacancy: {title, position, department, requirements}, candidate: {city, tags, name}, materials: [{channel, body}]} */
    public static function fromArray(array $input): self
    {
        $vacancy = (array) ($input['vacancy'] ?? []);
        $candidate = (array) ($input['candidate'] ?? []);
        $str = static fn (array $a, string $k): ?string => isset($a[$k]) && is_scalar($a[$k]) && trim((string) $a[$k]) !== '' ? (string) $a[$k] : null;
        $materials = [];
        foreach ((array) ($input['materials'] ?? []) as $m) {
            if (is_array($m) && isset($m['body']) && is_string($m['body'])) {
                $materials[] = ['channel' => is_string($m['channel'] ?? null) ? $m['channel'] : 'note', 'body' => $m['body']];
            }
        }

        return new self(
            vacancyTitle: (string) ($str($vacancy, 'title') ?? ''),
            position: $str($vacancy, 'position'),
            department: $str($vacancy, 'department'),
            requirements: $str($vacancy, 'requirements'),
            city: $str($candidate, 'city'),
            tags: array_values(array_map('strval', array_filter((array) ($candidate['tags'] ?? []), 'is_scalar'))),
            names: $str($candidate, 'name') === null ? [] : [(string) $str($candidate, 'name')],
            materials: $materials,
        );
    }
}
