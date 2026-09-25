<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Models\User;
use App\Modules\Integrations\Contracts\ConnectionChecker;
use App\Modules\Integrations\Contracts\IntegrationDefinition;
use App\Modules\Integrations\Contracts\IntegrationRepository;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\DTO\CheckResult;
use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\DTO\IntegrationView;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Enums\LogLevel;
use App\Modules\Integrations\Exceptions\IntegrationException;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\IntegrationLog;
use App\Modules\Integrations\Support\IntegrationRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Integrations admin: read (masked), update settings/secrets, run checks, switch status.
 * Audit goes to integration_logs with field NAMES only: values (secrets above all) are never logged.
 */
final class IntegrationService
{
    public const int LOG_LIMIT = 50;

    private const int ERROR_MAX_LENGTH = 255;

    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly IntegrationRepository $integrations,
        private readonly SecretVault $vault,
    ) {}

    /** @return list<IntegrationView> */
    public function list(): array
    {
        $rows = $this->integrations->allByKey();

        return array_map(
            fn (IntegrationDefinition $d): IntegrationView => $this->view($d, $rows->get($d->key())),
            $this->registry->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $settings  non-secret fields (already validated against the FieldSpec)
     * @param  array<string, string|null>  $secrets  value = set, null = delete, "" or absent = unchanged
     */
    public function update(User $actor, IntegrationDefinition $definition, array $settings, array $secrets): IntegrationView
    {
        $integration = $this->integrations->findOrCreate($definition->key());

        $current = $integration->settings;
        $next = $current;
        foreach ($this->fields($definition, secret: false) as $field) {
            if (array_key_exists($field->name, $settings)) {
                $next[$field->name] = $settings[$field->name];
            }
        }
        $changedSettings = array_values(array_filter(
            array_keys($next),
            static fn (string $n): bool => ($current[$n] ?? null) !== $next[$n],
        ));

        $set = [];
        $cleared = [];
        foreach ($this->fields($definition, secret: true) as $field) {
            if (! array_key_exists($field->name, $secrets) || $secrets[$field->name] === '') {
                continue;
            }
            $value = $secrets[$field->name];
            if ($value === null) {
                $this->vault->forget($definition->key(), $field->name);
                $cleared[] = $field->name;
            } else {
                $this->vault->put($definition->key(), $field->name, $value, $actor->id);
                $set[] = $field->name;
            }
        }

        if ($changedSettings !== []) {
            $integration->settings = $next;
            $this->integrations->save($integration);
        }
        if ($changedSettings !== [] || $set !== [] || $cleared !== []) {
            $this->integrations->log($integration, LogLevel::Info, 'settings_updated', [
                'user_id' => $actor->id,
                'settings' => $changedSettings,
                'secrets_set' => $set,
                'secrets_cleared' => $cleared,
            ]);
        }

        return $this->view($definition, $integration);
    }

    /** Runs the definition's checker and stores the outcome (status, scrubbed error, log). */
    public function check(User $actor, IntegrationDefinition $definition): IntegrationView
    {
        if (! $definition instanceof ConnectionChecker) {
            throw IntegrationException::checkNotSupported();
        }

        $config = $this->config($definition);
        $result = $this->missingSecret($definition, $config) ?? $definition->check($config);
        $error = $result->message === null ? null : $this->scrub($result->message, $config);

        $integration = $this->integrations->findOrCreate($definition->key());
        $integration->status = $result->status;
        $integration->last_checked_at = Carbon::now();
        $integration->last_error = $result->status === IntegrationStatus::Connected ? null : $error;
        $this->integrations->save($integration);
        $this->integrations->log(
            $integration,
            $result->status === IntegrationStatus::Error ? LogLevel::Error : LogLevel::Info,
            'check_'.$result->status->value,
            ['user_id' => $actor->id, 'result' => $error],
        );

        return $this->view($definition, $integration);
    }

    /** Manual switch to off|demo (validated by the request). */
    public function setStatus(User $actor, IntegrationDefinition $definition, IntegrationStatus $status): IntegrationView
    {
        $integration = $this->integrations->findOrCreate($definition->key());
        $previous = $integration->status;
        $integration->status = $status;
        $this->integrations->save($integration);
        $this->integrations->log($integration, LogLevel::Info, 'status_changed', [
            'user_id' => $actor->id,
            'from' => $previous->value,
            'to' => $status->value,
        ]);

        return $this->view($definition, $integration);
    }

    /** @return Collection<int, IntegrationLog> newest first */
    public function logs(IntegrationDefinition $definition): Collection
    {
        return $this->integrations->recentLogs($definition->key(), self::LOG_LIMIT);
    }

    private function view(IntegrationDefinition $definition, ?Integration $row): IntegrationView
    {
        return new IntegrationView(
            $definition,
            $row->status ?? IntegrationStatus::Off,
            $row->settings ?? [],
            $this->fields($definition, secret: true) === [] ? [] : $this->vault->describe($definition->key()),
            $row?->last_checked_at,
            $row?->last_error,
            $row?->updated_at,
        );
    }

    private function config(IntegrationDefinition $definition): IntegrationConfig
    {
        $stored = $this->integrations->find($definition->key())->settings ?? [];
        $settings = [];
        foreach ($this->fields($definition, secret: false) as $field) {
            $settings[$field->name] = $stored[$field->name] ?? $field->default;
        }
        $secrets = [];
        foreach ($this->fields($definition, secret: true) as $field) {
            $value = $this->vault->get($definition->key(), $field->name);
            if ($value !== null && $value !== '') {
                $secrets[$field->name] = $value;
            }
        }

        return new IntegrationConfig($definition->key(), $settings, $secrets);
    }

    private function missingSecret(IntegrationDefinition $definition, IntegrationConfig $config): ?CheckResult
    {
        foreach ($this->fields($definition, secret: true) as $field) {
            if ($field->required && $config->secret($field->name) === null) {
                return CheckResult::error('missing_secret:'.$field->name);
            }
        }

        return null;
    }

    /** Defence in depth: whatever a checker returns, secret values never reach the DB or the API. */
    private function scrub(string $message, IntegrationConfig $config): string
    {
        foreach ($config->secretValues() as $secret) {
            $message = str_replace($secret, '***', $message);
        }

        return mb_substr($message, 0, self::ERROR_MAX_LENGTH);
    }

    /** @return list<FieldSpec> */
    private function fields(IntegrationDefinition $definition, bool $secret): array
    {
        return array_values(array_filter(
            $definition->fields(),
            static fn (FieldSpec $f): bool => $f->isSecret() === $secret,
        ));
    }
}
