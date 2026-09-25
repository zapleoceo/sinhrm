<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Contracts;

use App\Modules\Scripts\DTO\EvaluationResult;
use App\Modules\Scripts\DTO\ScriptContent;
use App\Modules\Scripts\Enums\EvaluationEngine;
use App\Modules\Scripts\Exceptions\ScriptException;

/** Scores a text (call transcript or chat message) against a script version. */
interface ScriptEvaluator
{
    public function engine(): EvaluationEngine;

    /** @throws ScriptException when this engine cannot evaluate now (e.g. AI is switched off) */
    public function evaluate(ScriptContent $script, string $text): EvaluationResult;
}
