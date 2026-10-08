<?php

declare(strict_types=1);

namespace App\Modules\Time\Exceptions;

use App\Modules\Core\Exceptions\BusinessRuleException;

/** Business-rule violation in Time; rendered as {message, code, ...extra} with its HTTP status. */
final class TimeException extends BusinessRuleException
{
    /** The user has no employee record, so there is no own timesheet. */
    public static function noEmployee(): self
    {
        return new self('no_employee', 422);
    }

    /** Submitted/approved weeks are read-only (reject returns it to editing). */
    public static function notEditable(string $status): self
    {
        return new self('not_editable', 409, ['status' => $status]);
    }

    public static function invalidStatus(string $status): self
    {
        return new self('invalid_status', 409, ['status' => $status]);
    }

    /** An entry date outside the week. */
    public static function outsideWeek(string $date): self
    {
        return new self('outside_week', 422, ['date' => $date]);
    }

    /** More than 24 hours on one day. */
    public static function dayOverflow(string $date): self
    {
        return new self('day_overflow', 422, ['date' => $date]);
    }
}
