<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\Enums\Channel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /candidates/{candidate}/timeline?channel=call,telegram,stage — comma list or array; "stage" = stage changes.
 * No channel = everything.
 */
final class TimelineRequest extends FormRequest
{
    public const string STAGE = 'stage';

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('view', $this->route('candidate'));
    }

    protected function prepareForValidation(): void
    {
        $channel = $this->query('channel');
        if (is_string($channel)) {
            $this->merge(['channel' => array_values(array_filter(array_map('trim', explode(',', $channel)), static fn (string $c): bool => $c !== ''))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['nullable', 'array', 'max:10'],
            'channel.*' => ['string', Rule::in([...Channel::values(), self::STAGE])],
            'perPage' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    /** @return list<Channel>|null null = all channels */
    public function channels(): ?array
    {
        $list = $this->selected();
        if ($list === null) {
            return null;
        }

        return array_values(array_map(Channel::from(...), array_filter($list, static fn (string $c): bool => $c !== self::STAGE)));
    }

    public function withStages(): bool
    {
        $list = $this->selected();

        return $list === null || in_array(self::STAGE, $list, true);
    }

    public function perPage(): int
    {
        return $this->integer('perPage', 50);
    }

    /** @return list<string>|null */
    private function selected(): ?array
    {
        $list = $this->input('channel');
        if (! is_array($list) || $list === []) {
            return null;
        }

        return array_values(array_filter($list, is_string(...)));
    }
}
