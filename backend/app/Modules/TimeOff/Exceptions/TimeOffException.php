<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation in TimeOff; rendered as {message, code, ...extra} with its HTTP status. */
final class TimeOffException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    private function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }

    public static function insufficientBalance(float $available, float $requested): self
    {
        return new self('insufficient_balance', 422, ['available' => $available, 'requested' => $requested]);
    }

    /** Another pending/approved request of the same employee intersects the dates. */
    public static function overlap(): self
    {
        return new self('overlap', 422);
    }

    /** Only weekends/holidays in the range. */
    public static function noWorkingDays(): self
    {
        return new self('no_working_days', 422);
    }

    public static function rangeTooLong(int $maxDays): self
    {
        return new self('range_too_long', 422, ['max_days' => $maxDays]);
    }

    public static function inactiveType(): self
    {
        return new self('inactive_type', 422);
    }

    /** The request is not in a status that allows this action (or someone decided first). */
    public static function invalidStatus(): self
    {
        return new self('invalid_status', 409);
    }

    public static function forbidden(): self
    {
        return new self('forbidden', 403);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
