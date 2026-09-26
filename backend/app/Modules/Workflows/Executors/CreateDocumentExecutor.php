<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\Documents\Exceptions\DocumentException;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Documents\Services\DocumentTemplateService;
use App\Modules\Workflows\Contracts\StepExecutor;
use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;

/**
 * create_document: generates the employee's document from a document template (Documents module). With
 * "send" it is sent for acknowledgement at once; an employee without a login keeps a draft (result sent=false).
 */
final readonly class CreateDocumentExecutor implements StepExecutor
{
    public function __construct(private DocumentTemplateService $templates, private DocumentService $documents) {}

    public function action(): StepAction
    {
        return StepAction::CreateDocument;
    }

    public function configRules(): array
    {
        return [
            'document_template_id' => ['required', 'integer', Rule::exists(DocumentTemplate::class, 'id')],
            'send' => ['sometimes', 'boolean'],
        ];
    }

    public function execute(StepContext $context): StepOutcome
    {
        $templateId = $context->step->int('document_template_id');
        try {
            $template = $this->templates->find($templateId ?? 0);
        } catch (ModelNotFoundException) {
            return StepOutcome::failed('document_template_missing');
        }
        if ($template->archived) {
            return StepOutcome::failed('template_archived');
        }
        $document = $this->documents->generate(null, $context->employee, $template, today: $context->now);
        if (! $context->step->bool('send')) {
            return StepOutcome::done(['document_id' => $document->id, 'sent' => false]);
        }
        try {
            $this->documents->send($document, $context->now);
        } catch (DocumentException $e) {
            return StepOutcome::done(['document_id' => $document->id, 'sent' => false, 'reason' => $e->errorCode]);
        }

        return StepOutcome::done(['document_id' => $document->id, 'sent' => true]);
    }
}
