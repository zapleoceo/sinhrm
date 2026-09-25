<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Contracts;

use App\Modules\Integrations\DTO\FieldSpec;
use App\Modules\Integrations\Enums\IntegrationGroup;

/**
 * Describes one integration (its config form). To add an integration: create a class implementing this
 * interface (and ConnectionChecker if it can be checked) and add it to the tag list in IntegrationsServiceProvider.
 */
interface IntegrationDefinition
{
    /** Stable id, e.g. "telegram_business"; also the i18n key on the frontend. */
    public function key(): string;

    public function group(): IntegrationGroup;

    /** @return list<FieldSpec> */
    public function fields(): array;

    /** True only when the definition also implements ConnectionChecker. */
    public function supportsCheck(): bool;
}
