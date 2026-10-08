<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Exceptions;

use App\Modules\Core\Exceptions\BusinessRuleException;

/** Business-rule violation in Recruiting; rendered as {message, code, ...extra} with its HTTP status. */
final class RecruitingException extends BusinessRuleException
{
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

    /** Personal board (/candidates): at most $max own columns per vacancy. */
    public static function boardColumnLimit(int $max): self
    {
        return new self('board_column_limit', 422, ['max' => $max]);
    }

    /** Personal board: the column is of another vacancy, or the reorder list is not exactly the own columns. */
    public static function boardColumnMismatch(): self
    {
        return new self('board_column_mismatch', 422);
    }

    /** Personal board layout: an unknown / foreign / repeated column key. */
    public static function boardLayoutInvalid(): self
    {
        return new self('board_layout_invalid', 422);
    }

    /** Personal board layout: funnel stages must keep their own order (they can only be pushed apart). */
    public static function boardLayoutStageOrder(): self
    {
        return new self('board_layout_stage_order', 422);
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

    /** An import row / e-mail without phone, e-mail or Telegram: nothing to dedupe by. */
    public static function noContacts(): self
    {
        return new self('no_contacts', 422);
    }

    public static function fullNameRequired(): self
    {
        return new self('full_name_required', 422);
    }

    /** The channel code (technical name) is used by another channel. */
    public static function channelCodeTaken(): self
    {
        return new self('channel_code_taken', 422);
    }

    /** An explicit channel_id must point at an active channel. */
    public static function channelInactive(): self
    {
        return new self('channel_inactive', 422);
    }

    /** A UTM rule needs at least one of utm_source / utm_medium / utm_campaign. */
    public static function emptyUtmRule(): self
    {
        return new self('empty_utm_rule', 422);
    }

    public static function alreadyLinked(): self
    {
        return new self('already_linked', 409);
    }

    /** Offers are created only on an application in the offer stage (kind "hire", not terminal). */
    public static function notInOfferStage(): self
    {
        return new self('not_in_offer_stage', 422);
    }

    public static function offerExists(int $offerId): self
    {
        return new self('offer_exists', 409, ['offer_id' => $offerId]);
    }

    /** Wrong offer status for the action (send only a draft, accept/decline only a sent offer). */
    public static function offerStatus(): self
    {
        return new self('offer_status', 422);
    }

    public static function templateNotOffer(): self
    {
        return new self('template_not_offer', 422);
    }

    /** Public apply: too many submissions from the same client. */
    public static function tooManySubmissions(): self
    {
        return new self('too_many_requests', 429);
    }

    /** CV must be PDF, DOC or DOCX up to 2 MB (type detected from the bytes). */
    public static function invalidCv(): self
    {
        return new self('invalid_cv', 422);
    }
}
