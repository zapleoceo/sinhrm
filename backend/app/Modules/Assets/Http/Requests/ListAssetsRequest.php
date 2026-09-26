<?php

declare(strict_types=1);

namespace App\Modules\Assets\Http\Requests;

use App\Modules\Assets\Enums\AssetStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/assets?status=&type_id=&q= */
final class ListAssetsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(AssetStatus::class)],
            'type_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array{status?: string|null, type_id?: int|null, q?: string|null} */
    public function filter(): array
    {
        return [
            'status' => $this->enum('status', AssetStatus::class)?->value,
            'type_id' => $this->filled('type_id') ? $this->integer('type_id') : null,
            'q' => $this->filled('q') ? $this->string('q')->toString() : null,
        ];
    }
}
