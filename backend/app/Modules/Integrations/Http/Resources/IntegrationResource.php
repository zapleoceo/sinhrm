<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Resources;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\DTO\IntegrationView;
use App\Modules\Integrations\DTO\SecretMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One integration for the admin page. Secret fields expose only {is_set, updated_at, masked}:
 * the value itself is never part of any response.
 *
 * @property IntegrationView $resource
 */
final class IntegrationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $view = $this->resource;

        return [
            'key' => $view->definition->key(),
            'group' => $view->definition->group()->value,
            'status' => $view->status->value,
            'supports_check' => $view->definition->supportsCheck(),
            'last_checked_at' => $view->lastCheckedAt?->toIso8601String(),
            'last_error' => $view->lastError,
            'updated_at' => $view->updatedAt?->toIso8601String(),
            'fields' => array_map(fn (FieldSpec $f): array => $this->field($f, $view), $view->definition->fields()),
        ];
    }

    /** @return array<string, mixed> */
    private function field(FieldSpec $field, IntegrationView $view): array
    {
        $base = [
            'name' => $field->name,
            'type' => $field->type->value,
            'required' => $field->required,
            'options' => $field->options,
            'default' => $field->default,
        ];

        if ($field->isSecret()) {
            $meta = $view->secrets[$field->name] ?? new SecretMeta(false);

            return $base + ['secret' => [
                'is_set' => $meta->isSet,
                'updated_at' => $meta->updatedAt?->toIso8601String(),
                'masked' => $meta->masked,
            ]];
        }

        $value = $view->settings[$field->name] ?? null;

        return $base + ['value' => is_scalar($value) ? (string) $value : null];
    }
}
