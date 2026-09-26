<?php

declare(strict_types=1);

namespace App\Modules\Assets\Http\Requests;

use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST (inventory_number + name required) / PATCH /api/assets. "assigned" is refused here — use /assign. */
final class SaveAssetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'inventory_number' => [$required, 'string', 'max:64'],
            'name' => [$required, 'string', 'max:200'],
            'serial' => ['sometimes', 'nullable', 'string', 'max:120'],
            'type_id' => ['sometimes', 'nullable', 'integer', Rule::exists(AssetType::class, 'id')],
            'status' => ['sometimes', Rule::in(AssetStatus::returnValues())],
            'cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'purchased_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return $data;
    }
}
