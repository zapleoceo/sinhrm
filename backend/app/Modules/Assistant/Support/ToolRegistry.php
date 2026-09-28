<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Modules\Assistant\Contracts\AssistantTool;

/** Every tagged AssistantTool (AssistantServiceProvider::TOOLS_TAG), keyed by name, in a stable (sorted) order. */
final class ToolRegistry
{
    /** @var array<string, AssistantTool>|null */
    private ?array $tools = null;

    /** @param  iterable<AssistantTool>  $tagged */
    public function __construct(private readonly iterable $tagged) {}

    /** @return array<string, AssistantTool> */
    public function all(): array
    {
        if ($this->tools === null) {
            $tools = [];
            foreach ($this->tagged as $tool) {
                $tools[$tool->name()] = $tool;
            }
            ksort($tools);
            $this->tools = $tools;
        }

        return $this->tools;
    }

    public function find(string $name): ?AssistantTool
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Native tool definitions for the chat. Sorted and byte-stable: tools are part of the cached prompt prefix.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_values(array_map(static fn (AssistantTool $t): array => [
            'type' => 'function',
            'function' => ['name' => $t->name(), 'description' => $t->description(), 'parameters' => $t->parameters()],
        ], $this->all()));
    }
}
