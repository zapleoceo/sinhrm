<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation in Scripts; rendered as {message, code, ...extra} with its HTTP status. */
final class ScriptException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    private function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }

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

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
