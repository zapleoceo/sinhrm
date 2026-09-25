<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Support;

use App\Modules\Integrations\Contracts\IntegrationDefinition;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * All known integrations, collected from the container tag IntegrationsServiceProvider::DEFINITIONS_TAG.
 * Open/Closed: a new integration is one class + one tag line, the registry never changes.
 */
final class IntegrationRegistry
{
    /** @var array<string, IntegrationDefinition> */
    private array $definitions = [];

    /** @param  iterable<IntegrationDefinition>  $definitions */
    public function __construct(iterable $definitions)
    {
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->key()])) {
                throw new InvalidArgumentException("Duplicate integration key: {$definition->key()}");
            }
            $this->definitions[$definition->key()] = $definition;
        }
    }

    /** @return list<IntegrationDefinition> in registration order */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function find(string $key): ?IntegrationDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /** @throws NotFoundHttpException unknown key → 404 */
    public function get(string $key): IntegrationDefinition
    {
        return $this->find($key) ?? throw new NotFoundHttpException('Unknown integration.');
    }
}
