<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Contracts\AiResultHandler;
use App\Modules\Ai\Enums\AiPurpose;
use LogicException;

/**
 * Result handlers by purpose, collected from the container tag AiServiceProvider::HANDLERS_TAG. Resolved lazily on the
 * first get(): handlers depend on domain services that themselves use AiService (which needs this registry).
 */
final class AiHandlerRegistry
{
    /** @var array<string, AiResultHandler>|null */
    private ?array $resolved = null;

    /** @param  iterable<AiResultHandler>  $handlers */
    public function __construct(private readonly iterable $handlers) {}

    public function get(AiPurpose $purpose): AiResultHandler
    {
        if ($this->resolved === null) {
            $this->resolved = [];
            foreach ($this->handlers as $handler) {
                $this->resolved[$handler->purpose()->value] = $handler;
            }
        }

        return $this->resolved[$purpose->value] ?? throw new LogicException('No AI result handler for '.$purpose->value);
    }
}
