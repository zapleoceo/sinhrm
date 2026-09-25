<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Requests;

use App\Modules\Scripts\Enums\ScriptChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/scripts {name, channel, …optional content of draft version 1}. Access: route gate scripts-manage. */
final class SaveScriptRequest extends FormRequest
{
    use ValidatesScriptContent;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'channel' => ['required', Rule::in(ScriptChannel::values())],
        ] + $this->contentRules();
    }

    public function name(): string
    {
        return $this->string('name')->trim()->toString();
    }

    public function channel(): ScriptChannel
    {
        return ScriptChannel::from($this->string('channel')->toString());
    }
}
