<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Requests;

/** PUT /api/time/week {week, employee_id?, entries: [{date, hours (0.25..24), project?, category?, note?}]} — replaces the week. */
final class SaveEntriesRequest extends WeekRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'entries' => ['present', 'array', 'max:200'],
            'entries.*.date' => ['required', 'date_format:Y-m-d'],
            'entries.*.hours' => ['required', 'numeric', 'gt:0', 'max:24'],
            'entries.*.project' => ['nullable', 'string', 'max:120'],
            'entries.*.category' => ['nullable', 'string', 'max:60'],
            'entries.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<array{date: string, hours: float, project: string|null, category: string|null, note: string|null}> */
    public function entries(): array
    {
        $out = [];
        foreach ((array) $this->input('entries', []) as $e) {
            $e = (array) $e;
            $text = static fn (string $k): ?string => isset($e[$k]) && is_scalar($e[$k]) && trim((string) $e[$k]) !== '' ? trim((string) $e[$k]) : null;
            $out[] = [
                'date' => (string) $e['date'],
                'hours' => round((float) $e['hours'], 2),
                'project' => $text('project'),
                'category' => $text('category'),
                'note' => $text('note'),
            ];
        }

        return $out;
    }
}
