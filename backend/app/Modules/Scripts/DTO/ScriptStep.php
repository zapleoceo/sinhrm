<?php

declare(strict_types=1);

namespace App\Modules\Scripts\DTO;

/** One step of a script: what to say (sample), why (goal), how important (required, weight) and how to recognize it (keywords). */
final readonly class ScriptStep
{
    /** @param  list<string>  $keywords  lowercase, trimmed, unique */
    public function __construct(
        public string $id,
        public string $title,
        public string $goal,
        public string $sample,
        public bool $required,
        public int $weight,
        public array $keywords,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $keywords = [];
        foreach ((array) ($data['keywords'] ?? []) as $keyword) {
            $k = mb_strtolower(trim((string) $keyword));
            if ($k !== '' && ! in_array($k, $keywords, true)) {
                $keywords[] = $k;
            }
        }

        return new self(
            id: trim((string) ($data['id'] ?? '')),
            title: trim((string) ($data['title'] ?? '')),
            goal: trim((string) ($data['goal'] ?? '')),
            sample: trim((string) ($data['sample'] ?? '')),
            required: (bool) ($data['required'] ?? false),
            weight: max(0, min(100, (int) ($data['weight'] ?? 0))),
            keywords: $keywords,
        );
    }

    /** @return array{id: string, title: string, goal: string, sample: string, required: bool, weight: int, keywords: list<string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'goal' => $this->goal,
            'sample' => $this->sample,
            'required' => $this->required,
            'weight' => $this->weight,
            'keywords' => $this->keywords,
        ];
    }
}
