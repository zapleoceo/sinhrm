<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Http\Requests;

use App\Modules\SafeSpeak\Enums\ReportCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/safe-speak/public/reports — anonymous; nothing about the sender is read. */
final class SubmitReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(ReportCategory::class)],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ];
    }

    public function category(): ReportCategory
    {
        $category = $this->enum('category', ReportCategory::class);
        assert($category instanceof ReportCategory);

        return $category;
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
