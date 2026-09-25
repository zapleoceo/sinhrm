<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Enums;

/** Who scored a touch: keyword rules (always available) or AI (only when the global AI switch is on). */
enum EvaluationEngine: string
{
    case Rules = 'rules';
    case Ai = 'ai';
}
