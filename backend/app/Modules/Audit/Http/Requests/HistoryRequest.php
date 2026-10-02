<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\Paginates;
use Illuminate\Foundation\Http\FormRequest;

/** Paging for an entity "History" tab (access is checked by the owning module's route). */
final class HistoryRequest extends FormRequest
{
    use Paginates;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => $this->perPageRules(100),
        ];
    }

    public function page(): int
    {
        return $this->integer('page', 1);
    }

    public function perPage(): int
    {
        return $this->perPageOr(20);
    }
}
