<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Models\User;
use App\Modules\Documents\Contracts\DocumentTemplateRepository;
use App\Modules\Documents\Exceptions\DocumentException;
use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Support\MarkdownRenderer;
use App\Modules\Documents\Support\TemplateFiller;
use App\Modules\People\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/** Document templates (admin): CRUD without delete (archive), variable check, rendered preview. */
final readonly class DocumentTemplateService
{
    public function __construct(private DocumentTemplateRepository $templates) {}

    /** @return Collection<int, DocumentTemplate> */
    public function list(bool $withArchived): Collection
    {
        return $this->templates->list($withArchived);
    }

    /** @throws ModelNotFoundException<DocumentTemplate> */
    public function find(int $id): DocumentTemplate
    {
        return $this->templates->find($id) ?? throw (new ModelNotFoundException)->setModel(DocumentTemplate::class, [$id]);
    }

    /**
     * @param  array{name: string, body: string, category?: string|null}  $data
     *
     * @throws DocumentException unknown_variables
     */
    public function create(User $actor, array $data): DocumentTemplate
    {
        self::assertKnownVariables($data['body']);

        return $this->templates->create($data + ['created_by' => $actor->id]);
    }

    /**
     * @param  array<string, mixed>  $data  name?, body?, category?, archived?
     *
     * @throws DocumentException unknown_variables
     */
    public function update(DocumentTemplate $template, array $data): DocumentTemplate
    {
        if (isset($data['body']) && is_string($data['body'])) {
            self::assertKnownVariables($data['body']);
        }

        return $this->templates->update($template, $data);
    }

    /**
     * Rendered preview: with an employee's real values (admin chose one) or with synthetic sample values.
     *
     * @return array{markdown: string, html: string, missing: list<string>, unknown: list<string>}
     */
    public function preview(string $body, ?Employee $employee, ?Carbon $today = null): array
    {
        $today ??= Carbon::now();
        $values = $employee === null ? DocumentVariables::sample($today) : DocumentVariables::forEmployee($employee, $today);
        $filled = TemplateFiller::fill($body, $values);

        return [
            'markdown' => $filled['text'],
            'html' => MarkdownRenderer::toHtml($filled['text']),
            'missing' => $filled['missing'],
            'unknown' => TemplateFiller::unknown($body),
        ];
    }

    private static function assertKnownVariables(string $body): void
    {
        $unknown = TemplateFiller::unknown($body);
        if ($unknown !== []) {
            throw DocumentException::unknownVariables($unknown);
        }
    }
}
