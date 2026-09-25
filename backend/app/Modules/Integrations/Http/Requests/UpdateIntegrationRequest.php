<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Requests;

use App\Modules\Integrations\Contracts\IntegrationDefinition;
use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\FieldType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/integrations/{integration}: rules are generated from the definition's FieldSpec.
 * settings: non-secret fields; required fields must be present and non-empty.
 * secrets:  {name: "value"} set, {name: null} delete, {name: ""} or absent = unchanged.
 */
final class UpdateIntegrationRequest extends FormRequest
{
    private const int MAX_TEXT = 1000;

    private const int MAX_SECRET = 4096;

    public function definition(): IntegrationDefinition
    {
        $definition = $this->route('integration');
        assert($definition instanceof IntegrationDefinition);

        return $definition;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $plain = array_filter($this->definition()->fields(), static fn (FieldSpec $f): bool => ! $f->isSecret());
        $secret = array_filter($this->definition()->fields(), static fn (FieldSpec $f): bool => $f->isSecret());

        $rules = [
            'settings' => ['sometimes', 'array:'.implode(',', array_map(static fn (FieldSpec $f): string => $f->name, $plain))],
            'secrets' => ['sometimes', 'array:'.implode(',', array_map(static fn (FieldSpec $f): string => $f->name, $secret))],
        ];
        foreach ($plain as $field) {
            $rules['settings.'.$field->name] = $this->settingRules($field);
        }
        foreach ($secret as $field) {
            $rules['secrets.'.$field->name] = ['nullable', 'string', 'max:'.self::MAX_SECRET];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        /** @var array<string, mixed> $settings */
        $settings = $this->validated('settings', []);

        return $settings;
    }

    /** @return array<string, string|null> */
    public function secrets(): array
    {
        /** @var array<string, string|null> $secrets */
        $secrets = $this->validated('secrets', []);

        return $secrets;
    }

    /**
     * The global ConvertEmptyStringsToNull middleware turns "" into null, but for secrets "" (unchanged) and
     * null (delete) mean different things. Restore the secrets exactly as sent in the raw JSON body.
     */
    protected function prepareForValidation(): void
    {
        $raw = json_decode($this->getContent(), true);
        if (is_array($raw) && array_key_exists('secrets', $raw)) {
            $this->merge(['secrets' => $raw['secrets']]);
        }
    }

    /** @return list<mixed> */
    private function settingRules(FieldSpec $field): array
    {
        // Required fields: must be non-empty whenever the settings object is sent (partial PUT without settings is fine).
        $rules = $field->required ? ['required_with:settings'] : ['nullable'];

        return [...$rules, ...match ($field->type) {
            FieldType::Url => ['string', 'url:https', 'max:'.self::MAX_TEXT],
            FieldType::Select => ['string', Rule::in($field->options)],
            default => ['string', 'max:'.self::MAX_TEXT],
        }];
    }
}
