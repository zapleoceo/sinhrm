<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/pulse/mood/settings {weekdays: [1..7], question, required, alert_drop 0.1..4, min_group ≥ 5}. Admins. */
final class MoodSettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'weekdays' => ['present', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'question' => ['required', 'string', 'max:255'],
            'required' => ['required', 'boolean'],
            'alert_drop' => ['required', 'numeric', 'between:0.1,4'],
            'min_group' => ['required', 'integer', 'between:5,100'],
        ];
    }

    /** @return array{weekdays: list<int>, question: string, required: bool, alert_drop: float, min_group: int} */
    public function payload(): array
    {
        $days = array_values(array_unique(array_map('intval', (array) $this->input('weekdays', []))));
        sort($days);

        return [
            'weekdays' => $days,
            'question' => (string) $this->string('question'),
            'required' => $this->boolean('required'),
            'alert_drop' => (float) $this->input('alert_drop'),
            'min_group' => $this->integer('min_group'),
        ];
    }
}
