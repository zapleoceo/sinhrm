<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation in Pulse; rendered as {message, code, ...extra} with its HTTP status. */
final class PulseException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    private function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }

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

    /** The minimum group of a wave can only be raised (lowering would reveal groups hidden so far). */
    public static function minGroupLower(): self
    {
        return new self('min_group_lower', 422);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
