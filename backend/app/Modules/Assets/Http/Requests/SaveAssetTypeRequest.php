<?php

declare(strict_types=1);

namespace App\Modules\Assets\Http\Requests;

use App\Modules\Assets\Models\AssetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveAssetTypeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->route('type');

        return ['name' => ['required', 'string', 'max:120', Rule::unique(AssetType::class, 'name')->ignore(is_numeric($type) ? (int) $type : null)]];
    }

    public function name(): string
    {
        return trim($this->string('name')->toString());
    }
}
