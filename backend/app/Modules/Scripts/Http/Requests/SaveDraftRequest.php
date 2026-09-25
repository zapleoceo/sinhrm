<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/scripts/{script}/draft — the whole content of the draft (missing parts = empty; patterns → defaults). */
final class SaveDraftRequest extends FormRequest
{
    use ValidatesScriptContent;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->contentRules();
    }
}
