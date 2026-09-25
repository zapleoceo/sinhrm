<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST (name required) / PATCH (partial) of a reject reason. Access: route gate recruiting-manage. */
final class SaveRejectReasonRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'required', 'string', 'min:2', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function reasonAttributes(): array
    {
        $attributes = [];
        if ($this->has('name')) {
            $attributes['name'] = $this->string('name')->trim()->toString();
        }
        if ($this->has('active')) {
            $attributes['active'] = $this->boolean('active');
        }

        return $attributes;
    }
}
