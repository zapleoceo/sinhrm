<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;

/** upload_document_request: "provide the document <name>" task; link — the Documents tab of the profile. */
final class UploadDocumentRequestExecutor extends TaskStepExecutor
{
    public function action(): StepAction
    {
        return StepAction::UploadDocumentRequest;
    }

    public function configRules(): array
    {
        return ['document_name' => ['required', 'string', 'max:200']];
    }

    public function execute(StepContext $context): StepOutcome
    {
        $name = $context->step->string('document_name');
        $title = $name === null ? $context->step->title : $context->step->title.': '.$name;

        return $this->assignTask($context, $title, self::profileLink($context->employee->id, 'documents'));
    }
}
