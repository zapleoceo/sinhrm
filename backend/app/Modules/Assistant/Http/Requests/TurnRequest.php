<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/assistant/turn — the chat history (OpenAI message format, owned by the SPA) + the current page. The
 * history only shapes the model's answer: nothing in it is executed, so it is validated for shape and size only.
 */
final class TurnRequest extends FormRequest
{
    public const int MAX_MESSAGES = 60;

    public const int MAX_CONTENT = 14000;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'messages' => ['required', 'array', 'min:1', 'max:'.self::MAX_MESSAGES],
            'messages.*.role' => ['required', 'string', 'in:user,assistant,tool'],
            'messages.*.content' => ['nullable', 'string', 'max:'.self::MAX_CONTENT],
            'messages.*.tool_call_id' => ['required_if:messages.*.role,tool', 'string', 'max:100'],
            'messages.*.tool_calls' => ['sometimes', 'array', 'max:8'],
            'messages.*.tool_calls.*.id' => ['required', 'string', 'max:100'],
            'messages.*.tool_calls.*.function.name' => ['required', 'string', 'max:64'],
            'messages.*.tool_calls.*.function.arguments' => ['required', 'string', 'max:'.self::MAX_CONTENT],
            'page' => ['sometimes', 'array'],
            'page.path' => ['sometimes', 'string', 'max:300'],
            'page.title' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $messages = $this->input('messages');
            if (! is_array($messages) || $messages === []) {
                return;
            }
            $last = end($messages);
            if (! is_array($last) || ! in_array($last['role'] ?? null, ['user', 'tool'], true)) {
                $validator->errors()->add('messages', 'The last message must come from the user or a tool.');
            }
            foreach ($messages as $i => $m) {
                $hasCalls = is_array($m) && ! empty($m['tool_calls']);
                if (is_array($m) && ($m['content'] ?? null) === null && ! ($hasCalls && ($m['role'] ?? null) === 'assistant')) {
                    $validator->errors()->add("messages.$i.content", 'Content is required.');
                }
            }
        }];
    }

    /**
     * Clean history: only the fields the model format knows.
     *
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        $out = [];
        /** @var list<array<string, mixed>> $messages */
        $messages = $this->validated('messages');
        foreach ($messages as $m) {
            $message = ['role' => $m['role'], 'content' => $m['content'] ?? null];
            if ($m['role'] === 'tool') {
                $message['tool_call_id'] = $m['tool_call_id'];
            }
            if ($m['role'] === 'assistant' && ! empty($m['tool_calls']) && is_array($m['tool_calls'])) {
                $message['tool_calls'] = array_map(static fn (array $c): array => [
                    'id' => $c['id'],
                    'type' => 'function',
                    'function' => ['name' => $c['function']['name'], 'arguments' => $c['function']['arguments']],
                ], array_values($m['tool_calls']));
            }
            $out[] = $message;
        }

        return $out;
    }

    /** @return array{path: string, title: string} */
    public function page(): array
    {
        return [
            'path' => (string) $this->input('page.path', '/'),
            'title' => (string) $this->input('page.title', ''),
        ];
    }
}
