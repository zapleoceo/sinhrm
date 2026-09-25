<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Definitions;

use App\Modules\Integrations\Contracts\ConnectionChecker;
use App\Modules\Integrations\DTO\CheckResult;
use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\DTO\IntegrationConfig;
use App\Modules\Integrations\Enums\IntegrationGroup;

/**
 * Sintegrum API (source of branches/vacancies).
 * TODO: the auth scheme of the API is not confirmed yet, so the check makes NO network call: it only
 * validates the URL and the presence of the token and reports "demo / not_verified".
 */
final class SintegrumApiDefinition extends AbstractDefinition implements ConnectionChecker
{
    public const string DEFAULT_BASE_URL = 'https://api.sintegrum.com/v1/itstep';

    public function key(): string
    {
        return 'sintegrum_api';
    }

    public function group(): IntegrationGroup
    {
        return IntegrationGroup::Sources;
    }

    public function fields(): array
    {
        return [
            FieldSpec::url('base_url', required: true, default: self::DEFAULT_BASE_URL),
            FieldSpec::secret('token'),
        ];
    }

    public function check(IntegrationConfig $config): CheckResult
    {
        $base = $config->setting('base_url') ?? self::DEFAULT_BASE_URL;
        if (filter_var($base, FILTER_VALIDATE_URL) === false || ! str_starts_with($base, 'https://')) {
            return CheckResult::error('invalid_url');
        }
        if ($config->secret('token') === null) {
            return CheckResult::error('missing_secret:token');
        }

        return CheckResult::notVerified('not_verified');
    }
}
