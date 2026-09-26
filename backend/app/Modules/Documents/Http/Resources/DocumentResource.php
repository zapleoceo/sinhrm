<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Resources;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\Signature;
use App\Modules\Documents\Support\MarkdownRenderer;
use App\Modules\People\DTO\PeopleContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A document. The list omits the content; the single view adds content_md (admins, for editing) and html —
 * sanitized on the server (MarkdownRenderer), so the browser never renders raw user HTML.
 *
 * @mixin Document
 */
final class DocumentResource extends JsonResource
{
    private ?PeopleContext $context = null;

    private bool $detailed = false;

    public static function for(Document $document, PeopleContext $context, bool $detailed = false): self
    {
        $resource = new self($document);
        $resource->context = $context;
        $resource->detailed = $detailed;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $admin = $this->context !== null && $this->context->admin;
        $self = $this->context !== null && $this->context->isSelf($this->employee_id);
        $file = $this->file;

        $data = [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'status' => $this->status->value,
            'employee' => ['id' => $this->employee->id, 'full_name' => $this->employee->full_name],
            'template_id' => $this->template_id,
            'file' => $file === null ? null : ['filename' => $file->filename, 'mime' => $file->mime, 'size' => $file->size],
            'has_content' => trim((string) $this->content_md) !== '',
            'reject_reason' => $this->reject_reason,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'signatures' => $this->signatures->map(static fn (Signature $s): array => [
                'method' => $s->method->value,
                'signed_at' => $s->signed_at->toIso8601String(),
                'signer_employee_id' => $s->signer_employee_id,
            ])->values()->all(),
            'can_acknowledge' => $self && $this->status === DocumentStatus::Sent,
            'can_manage' => $admin,
        ];
        if ($this->detailed) {
            $data['content_md'] = $admin ? $this->content_md : null;
            $data['html'] = MarkdownRenderer::toHtml((string) $this->content_md);
        }

        return $data;
    }
}
