<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\DTO\TouchpointData;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** POST /candidates/{candidate}/touchpoints — a note, call log, meeting or a messenger/e-mail contact logged by hand. */
final class LogTouchpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('candidate'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(Channel::manualValues())],
            'direction' => ['sometimes', 'required', Rule::enum(Direction::class)],
            // A note without text is meaningless; for a call/meeting the fact itself is the information.
            'body' => ['nullable', 'string', 'max:10000', 'required_if:channel,'.Channel::Note->value],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:'.Carbon::now()->addDay()->toIso8601String()],
            'duration_sec' => ['nullable', 'integer', 'between:0,86400'],
            'application_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function touchpointData(): TouchpointData
    {
        $meta = [];
        if ($this->filled('duration_sec')) {
            $meta['duration_sec'] = $this->integer('duration_sec');
        }

        return new TouchpointData(
            channel: Channel::from($this->string('channel')->toString()),
            direction: $this->enum('direction', Direction::class) ?? Direction::Out,
            body: $this->filled('body') ? $this->string('body')->trim()->toString() : null,
            occurredAt: $this->filled('occurred_at') ? Carbon::parse($this->string('occurred_at')->toString()) : Carbon::now(),
            applicationId: $this->filled('application_id') ? $this->integer('application_id') : null,
            meta: $meta,
        );
    }
}
