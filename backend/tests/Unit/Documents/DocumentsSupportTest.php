<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Modules\Documents\Contracts\DocumentStorage;
use App\Modules\Documents\Enums\DocumentVariable;
use App\Modules\Documents\Exceptions\DocumentException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Repositories\DatabaseDocumentStorage;
use App\Modules\Documents\Services\DocumentVariables;
use App\Modules\Documents\Support\MarkdownRenderer;
use App\Modules\Documents\Support\TemplateFiller;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Variable filling, Markdown sanitization, file storage limits. Synthetic data only. */
final class DocumentsSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fill_replaces_known_variables_and_reports_missing_ones(): void
    {
        $result = TemplateFiller::fill("Dear {Ім'я} ({ПІБ}), position {Посада}; {Unknown} stays; {Ім'я} again", [
            "Ім'я" => 'Olena',
            'ПІБ' => 'Olena Sample',
            'Посада' => '  ',
        ]);

        $this->assertSame('Dear Olena (Olena Sample), position —; {Unknown} stays; Olena again', $result['text']);
        $this->assertSame(['Посада'], $result['missing']);
    }

    public function test_unknown_lists_each_bad_token_once(): void
    {
        // Known variables only; a brace span longer than 40 chars or across lines is plain text, not a token.
        $this->assertSame([], TemplateFiller::unknown('{ПІБ} {Сьогодні} {'.str_repeat('x', 41)."} {multi\nline}"));
        $this->assertSame(['Імя', 'Name'], TemplateFiller::unknown('{Імя} {Name} {Імя} {ПІБ}'));
        $this->assertCount(9, DocumentVariable::values());
    }

    public function test_variables_for_an_employee(): void
    {
        $manager = Employee::factory()->create(['full_name' => 'Iryna Lead']);
        $employee = Employee::factory()->create(['full_name' => 'Petro  Test', 'hired_at' => '2026-02-03', 'manager_id' => $manager->id]);

        $values = DocumentVariables::forEmployee($employee, Carbon::parse('2026-10-05'));

        $this->assertSame('Petro', $values["Ім'я"]);
        $this->assertSame('03.02.2026', $values['Дата прийому']);
        $this->assertNull($values['Дата звільнення']);
        $this->assertSame('Iryna Lead', $values['Керівник']);
        $this->assertSame('05.10.2026', $values['Сьогодні']);
    }

    public function test_markdown_is_rendered_and_raw_html_escaped(): void
    {
        $html = MarkdownRenderer::toHtml("**bold** <b onclick=x>raw</b>\n\n[ok](https://example.test) [bad](javascript:alert(1)) ![i](data:text/html;base64,PHNjcmlwdD4=)");

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('&lt;b onclick=x&gt;raw&lt;/b&gt;', $html);
        $this->assertStringContainsString('<a href="https://example.test">ok</a>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
    }

    public function test_storage_detects_type_from_bytes_and_enforces_size(): void
    {
        $this->assertSame('application/pdf', DatabaseDocumentStorage::detect("%PDF-1.4\n%%EOF\n", 'a.pdf'));
        $this->assertSame('image/png', DatabaseDocumentStorage::detect(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true) ?: '', 'p.png'));
        $this->assertNull(DatabaseDocumentStorage::detect('<html></html>', 'x.pdf'));
        $this->assertNull(DatabaseDocumentStorage::detect("PK\x03\x04 not really", 'x.zip'));
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            DatabaseDocumentStorage::detect("PK\x03\x04".str_repeat("\0", 30), 'contract.docx'),
        );
        $this->assertSame('evil.pdf', DatabaseDocumentStorage::safeName('..\\..//evil.pdf'));

        $document = Document::query()->create(['employee_id' => Employee::factory()->create()->id, 'title' => 't']);
        $storage = $this->app->make(DocumentStorage::class);
        try {
            $storage->put($document, str_repeat('a', DocumentStorage::MAX_BYTES + 1), 'big.pdf');
            $this->fail('oversize accepted');
        } catch (DocumentException $e) {
            $this->assertSame('file_too_large', $e->errorCode);
        }
        $ref = $storage->put($document, "%PDF-1.4\n%%EOF\n", 'a.pdf');
        $this->assertStringStartsWith('db:', $ref);
        $this->assertSame("%PDF-1.4\n%%EOF\n", $storage->get($document)?->content);
        $storage->delete($document);
        $this->assertNull($storage->get($document));
    }
}
