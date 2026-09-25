<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\Services\StalenessService;
use Illuminate\Foundation\Http\FormRequest;

/** GET /recruiting/stale?days=3 — days arrives as a string from the query; 'integer' accepts "3". */
final class StaleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['days' => ['nullable', 'integer', 'between:1,365']];
    }

    public function days(): int
    {
        return $this->integer('days', StalenessService::DEFAULT_DAYS);
    }
}
