<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\Enums\AcquisitionChannelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/acquisition-channels (code + name required) and PATCH …/{id} (partial). Gate recruiting-manage on the route. */
final class SaveAcquisitionChannelRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'code' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/'],
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:120'],
            'type' => ['sometimes', 'required', Rule::enum(AcquisitionChannelType::class)],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function channelAttributes(): array
    {
        $out = [];
        foreach (['code', 'name', 'type', 'active'] as $key) {
            if ($this->has($key)) {
                $out[$key] = $key === 'active' ? $this->boolean($key) : trim((string) $this->input($key));
            }
        }

        return $out;
    }
}
