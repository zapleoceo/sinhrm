<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Contracts\AiPromptTemplate;
use App\Modules\Ai\Enums\AiPurpose;

/** Prompt templates by purpose (container tag AiServiceProvider::PROMPTS_TAG); used by the experiment harness. */
final class AiPromptRegistry
{
    /** @var array<string, AiPromptTemplate>|null */
    private ?array $resolved = null;

    /** @param  iterable<AiPromptTemplate>  $templates */
    public function __construct(private readonly iterable $templates) {}

    public function find(AiPurpose $purpose): ?AiPromptTemplate
    {
        if ($this->resolved === null) {
            $this->resolved = [];
            foreach ($this->templates as $template) {
                $this->resolved[$template->purpose()->value] = $template;
            }
        }

        return $this->resolved[$purpose->value] ?? null;
    }
}
