<?php

declare(strict_types=1);

namespace Tests\Feature\MailAgent;

use App\Modules\MailAgent\Models\MailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MysqlSchemaAssertions;
use Tests\TestCase;

/** mail_messages.gmail_id keeps the source message identity: binary collation + unique on MySQL 8.4 (ADR 0011). */
final class GmailIdMysqlSchemaTest extends TestCase
{
    use MysqlSchemaAssertions, RefreshDatabase;

    public function test_gmail_ids_differing_only_in_case_are_two_messages(): void
    {
        $this->assertColumnCollation('mail_messages', 'gmail_id', 'utf8mb4_bin');
        $this->assertSame(['gmail_id'], $this->indexParts('mail_messages', 'mail_messages_gmail_id_unique'));

        $upper = MailMessage::query()->create(['gmail_id' => '18aB', 'received_at' => now(), 'outcome' => 'skipped']);
        $lower = MailMessage::query()->create(['gmail_id' => '18ab', 'received_at' => now(), 'outcome' => 'skipped']);

        $this->assertNotSame($upper->id, $lower->id);
        $this->assertSame($lower->id, MailMessage::query()->where('gmail_id', '18ab')->value('id'));
    }
}
