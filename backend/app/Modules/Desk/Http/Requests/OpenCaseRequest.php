<?php

declare(strict_types=1);

namespace App\Modules\Desk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/desk/cases — the employee opens a case for themself. */
final class OpenCaseRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ];
    }

    public function categoryId(): int
    {
        return $this->integer('category_id');
    }

    public function subject(): string
    {
        return trim($this->string('subject')->toString());
    }

    public function body(): string
    {
        return $this->string('body')->toString();
    }
}
