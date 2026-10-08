<?php

declare(strict_types=1);

namespace App\Modules\Core\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base of the module business-rule exceptions: a stable error code (the i18n key on the frontend), its HTTP status and
 * optional extra fields; Laravel renders it as {message: code, code, ...extra}. Modules add named static constructors
 * (`PeopleException::noEmployee()`), the constructor stays non-public.
 */
abstract class BusinessRuleException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    final protected function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->errorCode, 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
