<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Exceptions;

use App\Modules\Core\Exceptions\BusinessRuleException;

/** Business-rule violation in Pulse; rendered as {message, code, ...extra} with its HTTP status. */
final class PulseException extends BusinessRuleException
{
    public static function noEmployee(): self
    {
        return new self('no_employee', 422);
    }

    public static function waveNotOpen(): self
    {
        return new self('wave_not_open', 409);
    }

    /** The respondent is not in the wave's audience. */
    public static function notInAudience(): self
    {
        return new self('not_in_audience', 403);
    }

    public static function alreadyResponded(): self
    {
        return new self('already_responded', 409);
    }

    /** @param  list<string>  $questionIds */
    public static function invalidAnswers(array $questionIds): self
    {
        return new self('invalid_answers', 422, ['questions' => $questionIds]);
    }

    /** Answers on someone's behalf: only for an exit wave (not hire_30/hire_90, not a team wave). */
    public static function notExitWave(): self
    {
        return new self('not_exit_wave', 409);
    }

    /** Answers on someone's behalf: the employee still works and can log in — they answer themself. */
    public static function employeeCanAnswer(): self
    {
        return new self('employee_can_answer', 409);
    }

    /** Individual responses of an anonymous wave do not exist by design. */
    public static function anonymousWave(): self
    {
        return new self('anonymous_wave', 409);
    }

    /** Questions of a survey that already has responses cannot change (results would stop matching). */
    public static function hasResponses(): self
    {
        return new self('has_responses', 409);
    }

    /** A lifecycle survey runs personal waves only: a manual (team) wave of it would make its answers readable as a team. */
    public static function lifecycleSurvey(): self
    {
        return new self('lifecycle_survey', 409);
    }

    /** A survey that already has waves cannot become a lifecycle one (its team releases would turn personal). */
    public static function hasWaves(): self
    {
        return new self('has_waves', 409);
    }

    /** The minimum group of a wave can only be raised (lowering would reveal groups hidden so far). */
    public static function minGroupLower(): self
    {
        return new self('min_group_lower', 422);
    }
}
