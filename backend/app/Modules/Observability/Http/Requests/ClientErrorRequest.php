<?php

declare(strict_types=1);

namespace App\Modules\Observability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/errors/client — what the SPA may send. No stack, no request/response bodies: only the error kind,
 * its message, the first code location of the stack and the SPA route (path without query).
 */
final class ClientErrorRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_. :-]+$/'],
            'message' => ['required', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:300'],
            'route' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function kind(): string
    {
        return $this->string('kind')->toString();
    }

    public function errorMessage(): string
    {
        return $this->string('message')->toString();
    }

    public function location(): ?string
    {
        return $this->filled('location') ? $this->string('location')->toString() : null;
    }

    /** SPA path only: a query string may carry search terms or tokens. */
    public function spaRoute(): ?string
    {
        return $this->filled('route') ? strtok($this->string('route')->toString(), '?#') ?: null : null;
    }
}
