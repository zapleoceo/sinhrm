<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Http\Requests;

use App\Models\User;
use App\Modules\HiringRequests\Enums\RouteStepKind;
use App\Modules\HiringRequests\Support\FormFields;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/hiring-requests/settings {form_fields?, creator_user_ids?, auto_vacancy?, route?}. Admins (route gate).
 * Field keys: lowercase latin, digits, "_" and unique; select fields need options.
 */
final class SaveHiringSettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $uniqueKeys = static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_array($value)) {
                $keys = array_map(static fn (mixed $f): string => is_array($f) && isset($f['key']) && is_scalar($f['key']) ? (string) $f['key'] : '', $value);
                if (count($keys) !== count(array_unique($keys))) {
                    $fail('The field keys must be unique.');
                }
            }
        };

        return [
            'form_fields' => ['sometimes', 'array', 'max:'.FormFields::MAX_FIELDS, $uniqueKeys],
            'form_fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
            'form_fields.*.label' => ['required', 'string', 'max:120'],
            'form_fields.*.type' => ['required', Rule::in(FormFields::TYPES)],
            'form_fields.*.required' => ['sometimes', 'boolean'],
            'form_fields.*.options' => ['required_if:form_fields.*.type,select', 'array', 'max:50'],
            'form_fields.*.options.*' => ['string', 'max:120'],
            'creator_user_ids' => ['sometimes', 'array', 'max:200'],
            'creator_user_ids.*' => ['integer', Rule::exists(User::class, 'id')],
            'auto_vacancy' => ['sometimes', 'boolean'],
            'route' => ['sometimes', 'array', 'min:1', 'max:10'],
            'route.*.name' => ['required', 'string', 'max:120'],
            'route.*.kind' => ['required', Rule::enum(RouteStepKind::class)],
            'route.*.role' => ['nullable', 'string', 'max:32'],
            'route.*.user_id' => ['nullable', 'integer'],
            'route.*.sla_days' => ['nullable', 'integer', 'between:1,60'],
        ];
    }

    /** @return array{form_fields?: list<array<string, mixed>>, creator_user_ids?: list<int>, auto_vacancy?: bool} */
    public function settingsData(): array
    {
        $out = [];
        if ($this->has('form_fields')) {
            /** @var list<array<string, mixed>> $fields */
            $fields = array_values((array) $this->input('form_fields'));
            $out['form_fields'] = $fields;
        }
        if ($this->has('creator_user_ids')) {
            $out['creator_user_ids'] = array_values(array_map('intval', (array) $this->input('creator_user_ids')));
        }
        if ($this->has('auto_vacancy')) {
            $out['auto_vacancy'] = $this->boolean('auto_vacancy');
        }

        return $out;
    }

    /** @return list<array{name: string, kind: string, role: string|null, user_id: int|null, sla_days: int|null}>|null */
    public function routeSteps(): ?array
    {
        if (! $this->has('route')) {
            return null;
        }
        $steps = [];
        foreach ((array) $this->input('route') as $s) {
            $s = (array) $s;
            $steps[] = [
                'name' => trim((string) $s['name']),
                'kind' => (string) $s['kind'],
                'role' => isset($s['role']) ? (string) $s['role'] : null,
                'user_id' => isset($s['user_id']) ? (int) $s['user_id'] : null,
                'sla_days' => isset($s['sla_days']) ? (int) $s['sla_days'] : null,
            ];
        }

        return $steps;
    }
}
