<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST/PUT /api/perform/one-on-one-templates {name, agenda: list<string>}. Admins. */
final class SaveOneOnOneTemplateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'agenda' => ['present', 'array', 'max:50'],
            'agenda.*' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array{name: string, agenda: list<string>} */
    public function payload(): array
    {
        /** @var list<string> $agenda */
        $agenda = array_values((array) $this->input('agenda', []));

        return ['name' => (string) $this->string('name'), 'agenda' => $agenda];
    }
}
