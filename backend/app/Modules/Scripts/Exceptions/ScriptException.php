<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Exceptions;

use App\Modules\Core\Exceptions\BusinessRuleException;

/** Business-rule violation in Scripts; rendered as {message, code, ...extra} with its HTTP status. */
final class ScriptException extends BusinessRuleException
{
    /** Publish without a draft: nothing to publish. */
    public static function noDraft(): self
    {
        return new self('no_draft', 422);
    }

    /** Activate a version that does not exist or is still a draft. */
    public static function versionNotPublished(int $version): self
    {
        return new self('version_not_published', 422, ['version' => $version]);
    }

    /** Test/evaluate a script that has neither a draft nor an active version. */
    public static function nothingToEvaluate(): self
    {
        return new self('nothing_to_evaluate', 422);
    }

    public static function archived(): self
    {
        return new self('script_archived', 422);
    }

    /**
     * The AI evaluator cannot give a result now: AI off/not configured/over the cap, provider error, invalid output
     * or still pending (code = the Ai module code, e.g. ai_disabled). EvaluationService falls back to the rules.
     */
    public static function aiUnavailable(string $code): self
    {
        return new self($code, 422);
    }

    public static function notEvaluated(): self
    {
        return new self('not_evaluated', 404);
    }
}
