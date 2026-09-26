<?php

declare(strict_types=1);

namespace App\Modules\Assets\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Business-rule violation in Assets; rendered as {message, code} with its HTTP status. */
final class AssetException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }

    public static function inventoryNumberTaken(): self
    {
        return new self('inventory_number_taken', 422);
    }

    /** Only an asset in stock can be handed out. */
    public static function notInStock(): self
    {
        return new self('not_in_stock', 409);
    }

    public static function notAssigned(): self
    {
        return new self('not_assigned', 409);
    }

    /** "assigned" is set by assigning, not by editing. */
    public static function statusViaAssign(): self
    {
        return new self('status_via_assign', 422);
    }

    public static function employeeTerminated(): self
    {
        return new self('employee_terminated', 422);
    }

    public static function returnBeforeAssign(): self
    {
        return new self('return_before_assign', 422);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode], $this->status);
    }
}
