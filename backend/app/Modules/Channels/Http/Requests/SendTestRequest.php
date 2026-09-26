<?php

declare(strict_types=1);

namespace App\Modules\Channels\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /channels/{key}/test — recipient as the provider knows it: Telegram chat id, phone, Viber user id. */
final class SendTestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', 'max:64'],
            'text' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function to(): string
    {
        return $this->string('to')->trim()->toString();
    }

    public function text(): string
    {
        return $this->filled('text') ? $this->string('text')->trim()->toString() : 'SinHRM: test message';
    }
}
