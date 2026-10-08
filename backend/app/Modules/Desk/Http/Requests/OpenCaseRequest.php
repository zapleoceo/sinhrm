<?php

declare(strict_types=1);

namespace App\Modules\Desk\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\HasSubjectAndBody;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/desk/cases — the employee opens a case for themself. */
final class OpenCaseRequest extends FormRequest
{
    use HasSubjectAndBody;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer'],
            ...$this->subjectAndBodyRules(),
        ];
    }

    public function categoryId(): int
    {
        return $this->integer('category_id');
    }
}
