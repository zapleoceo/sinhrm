<?php

declare(strict_types=1);

namespace App\Modules\Channels\Http\Requests;

use App\Modules\Recruiting\Enums\Channel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /candidates/{candidate}/messages — same right as logging a touch (CandidatePolicy::update). */
final class SendMessageRequest extends FormRequest
{
    /** Channels that can be sent from the card. */
    public const array CHANNELS = [Channel::Telegram, Channel::Whatsapp, Channel::Viber];

    /** Longest text all three messengers accept in one message (Telegram: 4096). */
    public const int MAX_TEXT = 4096;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('candidate'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(array_map(static fn (Channel $c): string => $c->value, self::CHANNELS))],
            'text' => ['required', 'string', 'max:'.self::MAX_TEXT],
            'application_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function channel(): Channel
    {
        return Channel::from($this->string('channel')->toString());
    }

    public function text(): string
    {
        return $this->string('text')->trim()->toString();
    }

    public function applicationId(): ?int
    {
        return $this->filled('application_id') ? $this->integer('application_id') : null;
    }
}
