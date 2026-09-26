<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Services;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\HiringRequests\Contracts\HiringRequestRepository;
use App\Modules\HiringRequests\Enums\RouteStepKind;
use App\Modules\HiringRequests\Exceptions\HiringException;
use App\Modules\HiringRequests\Models\HiringRouteStep;
use App\Modules\HiringRequests\Models\HiringSettings;
use Illuminate\Database\Eloquent\Collection;

/**
 * HR settings (admins): the approval route template, the configurable form fields (order = array order, flag
 * "required"), who may create requests besides admins and managers, and whether approval opens the vacancy.
 * Changes apply to requests submitted afterwards (each request keeps its own route copy).
 */
final readonly class HiringSettingsService
{
    public function __construct(private HiringRequestRepository $requests) {}

    public function settings(): HiringSettings
    {
        return $this->requests->settings();
    }

    /** @return list<array{id: int, name: string}> */
    public function users(): array
    {
        return $this->requests->activeUsers(500);
    }

    /** @return Collection<int, HiringRouteStep> */
    public function route(): Collection
    {
        return $this->requests->routeSteps();
    }

    /**
     * @param  array{form_fields?: list<array<string, mixed>>, creator_user_ids?: list<int>, auto_vacancy?: bool}  $data
     */
    public function save(array $data): HiringSettings
    {
        $attributes = [];
        if (array_key_exists('form_fields', $data)) {
            $attributes['form_fields'] = array_map(static function (array $f): array {
                $field = [
                    'key' => (string) $f['key'],
                    'label' => (string) $f['label'],
                    'type' => (string) $f['type'],
                    'required' => (bool) ($f['required'] ?? false),
                ];
                if ($field['type'] === 'select') {
                    $field['options'] = array_values(array_map('strval', is_array($f['options'] ?? null) ? $f['options'] : []));
                }

                return $field;
            }, $data['form_fields']);
        }
        if (array_key_exists('creator_user_ids', $data)) {
            $attributes['creator_user_ids'] = array_values(array_unique(array_map('intval', $data['creator_user_ids'])));
        }
        if (array_key_exists('auto_vacancy', $data)) {
            $attributes['auto_vacancy'] = $data['auto_vacancy'];
        }

        return $this->requests->saveSettings($attributes);
    }

    /**
     * Replaces the route. Every step: name, kind; role steps need a known role, user steps an active user.
     *
     * @param  list<array{name: string, kind: string, role?: string|null, user_id?: int|null, sla_days?: int|null}>  $steps
     * @return Collection<int, HiringRouteStep>
     *
     * @throws HiringException invalid_route
     */
    public function saveRoute(array $steps): Collection
    {
        if ($steps === []) {
            throw HiringException::invalidRoute();
        }
        $clean = [];
        foreach ($steps as $s) {
            $kind = RouteStepKind::tryFrom($s['kind']) ?? throw HiringException::invalidRoute();
            $role = $kind === RouteStepKind::Role ? ($s['role'] ?? null) : null;
            $userId = $kind === RouteStepKind::User ? ($s['user_id'] ?? null) : null;
            if ($kind === RouteStepKind::Role && ! in_array($role, UserRole::values(), true)) {
                throw HiringException::invalidRoute();
            }
            if ($kind === RouteStepKind::User && ($userId === null || ! $this->requests->isActiveUser($userId))) {
                throw HiringException::invalidRoute();
            }
            $clean[] = ['name' => $s['name'], 'kind' => $kind->value, 'role' => $role, 'user_id' => $userId, 'sla_days' => $s['sla_days'] ?? null];
        }
        $this->requests->transaction(fn () => $this->requests->replaceRoute($clean));

        return $this->requests->routeSteps();
    }
}
