<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation in Recruiting; rendered as {message, code, ...extra} with its HTTP status. */
final class RecruitingException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    private function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }

    /** Same person already exists (phone / e-mail / Telegram match). */
    public static function duplicateCandidate(int $existingId, string $matchedBy): self
    {
        return new self('duplicate_candidate', 409, ['existing_id' => $existingId, 'matched_by' => $matchedBy]);
    }

    /** The same person exists but the caller may not see them (another branch): no id, no matched field. */
    public static function duplicateCandidateRestricted(): self
    {
        return new self('duplicate_candidate', 409, ['restricted' => true]);
    }

    public static function alreadyApplied(int $applicationId): self
    {
        return new self('already_applied', 409, ['application_id' => $applicationId]);
    }

    public static function stageNotInPipeline(): self
    {
        return new self('stage_not_in_pipeline', 422);
    }

    public static function sameStage(): self
    {
        return new self('same_stage', 422);
    }

    public static function rejectReasonRequired(): self
    {
        return new self('reject_reason_required', 422);
    }

    /** The given application belongs to another candidate. */
    public static function applicationMismatch(): self
    {
        return new self('application_mismatch', 422);
    }

    public static function noDefaultPipeline(): self
    {
        return new self('no_default_pipeline', 422);
    }

    public static function vacancyOutOfScope(): self
    {
        return new self('vacancy_out_of_scope', 403);
    }

    public static function alreadyLinked(): self
    {
        return new self('already_linked', 409);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
