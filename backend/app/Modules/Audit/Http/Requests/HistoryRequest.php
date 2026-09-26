<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Paging for an entity "History" tab (access is checked by the owning module's route). */
final class HistoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return $this->integer('perPage', 20);
    }
}
