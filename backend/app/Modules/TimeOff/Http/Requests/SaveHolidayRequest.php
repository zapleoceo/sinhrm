<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Requests;

use App\Modules\Directory\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /timeoff/holidays {date, name, branch_id?}, PATCH /timeoff/holidays/{holiday}. Admin (route gate). */
final class SaveHolidayRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'date' => [$req, 'required', 'date_format:Y-m-d'],
            'name' => [$req, 'required', 'string', 'max:255'],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Branch::class, 'id')],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        $attributes = [];
        if ($this->has('date')) {
            $attributes['date'] = $this->string('date')->toString();
        }
        if ($this->has('name')) {
            $attributes['name'] = $this->string('name')->trim()->toString();
        }
        if ($this->has('branch_id')) {
            $attributes['branch_id'] = $this->filled('branch_id') ? $this->integer('branch_id') : null;
        }

        return $attributes;
    }
}
