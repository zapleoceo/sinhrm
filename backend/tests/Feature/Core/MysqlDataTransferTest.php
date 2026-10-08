<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Services\Transfer\TransferDatabases;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Repositories\DatabaseDocumentStorage;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\People\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `db:transfer-to-mysql` against real PostgreSQL 17 + MySQL 8.4 with synthetic data. Runs only in the
 * mysql-data-transfer workflow (env TRANSFER_SOURCE_URL / TRANSFER_TARGET_URL on 127.0.0.1); skipped elsewhere.
 * Default connection = the source, so factories write synthetic rows into PostgreSQL.
 */
final class MysqlDataTransferTest extends TestCase
{
    private const string TARGET_DB = 'app_transfer_target';

    private const string SECRET = 'synthetic-transfer-secret';

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        if ((string) getenv('TRANSFER_TARGET_URL') === '' || getenv('GITHUB_ACTIONS') !== 'true') {
            $this->markTestSkipped('Only the mysql-data-transfer workflow runs the cross-database transfer.');
        }
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('app_transfer_source', DB::connection()->getDatabaseName());
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->ensureFixtures();
    }

    public function test_preflight_blocks_unique_collisions_without_printing_values(): void
    {
        $emails = ['collide.transfer@example.test', 'COLLIDE.transfer@example.test', 'йосип.transfer@example.test', 'иосип.transfer@example.test'];
        $ids = array_map(fn (string $email): int => User::factory()->create(['email' => $email])->id, $emails);
        try {
            [$code, $out] = $this->transfer(['--preflight' => true]);
            fwrite(STDERR, $out); // synthetic data only: the CI log shows the report format
            $this->assertSame(1, $code, $out);
            $this->assertStringContainsString('unique_collision', $out);
            $this->assertStringContainsString('users_email_unique', $out);
            $this->assertStringContainsString('2 групп', $out, $out);
            $this->assertStringContainsString('перенос не начат', $out);
            foreach ($emails as $email) {
                $this->assertStringNotContainsString(mb_strtolower($email), mb_strtolower($out));
            }

            $before = $this->target()->table('users')->count();
            [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);
            $this->assertSame(1, $code, $out);
            $this->assertSame($before, $this->target()->table('users')->count(), 'nothing is written or truncated');
        } finally {
            User::query()->whereIn('id', $ids)->delete();
        }
    }

    public function test_transfer_is_idempotent_resumable_and_keeps_attachments_and_ciphertexts(): void
    {
        [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('ИТОГ: OK', $out);

        [$code, $out] = $this->transfer(['--confirm-target' => self::TARGET_DB]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Скопировано строк: 0', $out);
        $this->assertStringContainsString('ИТОГ: OK', $out);

        // A crash in the middle: the tail of users is missing on the target; the re-run copies exactly that tail.
        $target = $this->target();
        $total = $target->table('users')->count();
        $cut = (int) $target->table('users')->orderBy('id')->skip(intdiv($total, 2))->value('id');
        $target->statement('SET FOREIGN_KEY_CHECKS=0');
        $removed = $target->table('users')->where('id', '>=', $cut)->delete();
        $target->statement('SET FOREIGN_KEY_CHECKS=1');
        $this->assertGreaterThan(0, $removed);
        [$code, $out] = $this->transfer(['--confirm-target' => self::TARGET_DB]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString("Скопировано строк: {$removed}", $out);
        $this->assertStringContainsString('ИТОГ: OK', $out);

        $target = $this->target();
        $this->assertSame(DB::table('users')->count(), $target->table('users')->count());
        $file = $target->table('documents_files')->where('filename', 'transfer-fixture.pdf')->sole();
        $this->assertSame(hash('sha256', $this->pdf()), $file->sha256);
        $this->assertSame($this->pdf(), base64_decode((string) $file->content, true));
        $ciphertext = $target->table('integration_secrets')->where('name', 'transfer-fixture')->value('value');
        $this->assertSame(DB::table('integration_secrets')->where('name', 'transfer-fixture')->value('value'), $ciphertext);
        $this->assertSame(self::SECRET, Crypt::decryptString((string) $ciphertext));
    }

    public function test_verify_reports_fail_on_corruption_without_printing_contents(): void
    {
        [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);
        $this->assertSame(0, $code, $out);

        $target = $this->target();
        $file = $target->table('documents_files')->where('filename', 'transfer-fixture.pdf')->sole();
        $target->table('documents_files')->where('id', $file->id)->update(['content' => base64_encode('tampered-content')]);
        $user = $target->table('users')->orderBy('id')->first();
        $this->assertNotNull($user);
        $target->table('users')->where('id', $user->id)->update(['name' => 'Corrupted Name']);

        [$code, $out] = $this->transfer(['--verify' => true]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('ИТОГ: FAIL', $out);
        $this->assertMatchesRegularExpression('/checksum\s*\|\s*documents_files/', $out);
        $this->assertMatchesRegularExpression('/attachments\s*\|\s*documents_files/', $out);
        $this->assertMatchesRegularExpression('/checksum\s*\|\s*users/', $out);
        $this->assertStringNotContainsString('Corrupted Name', $out);
        $this->assertStringNotContainsString(base64_encode('tampered-content'), $out);
        $this->assertStringNotContainsString((string) $user->email, $out);

        $this->target()->table('documents_files')->where('id', $file->id)->update(['content' => $file->content]);
        $this->target()->table('users')->where('id', $user->id)->update(['name' => $user->name]);
        [$code, $out] = $this->transfer(['--verify' => true]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('ИТОГ: OK', $out);
    }

    public function test_foreign_key_orphan_and_auto_increment_drift_fail_the_verification(): void
    {
        [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);
        $this->assertSame(0, $code, $out);
        $target = $this->target();
        $document = $target->table('documents')->where('title', 'Transfer fixture document')->sole();
        $target->statement('SET FOREIGN_KEY_CHECKS=0');
        $target->table('documents')->where('id', $document->id)->update(['employee_id' => 999999999]);
        $target->statement('SET FOREIGN_KEY_CHECKS=1');
        $target->statement('ALTER TABLE `candidates` AUTO_INCREMENT = 900000');

        [$code, $out] = $this->transfer(['--verify' => true]);
        $this->assertSame(1, $code, $out);
        $this->assertMatchesRegularExpression('/foreign_key\s*\|\s*documents/', $out);
        $this->assertMatchesRegularExpression('/auto_increment\s*\|\s*candidates/', $out);
    }

    public function test_wrong_app_key_stops_before_any_write(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app->forgetInstance('encrypter');

        [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);

        $this->assertSame(1, $code, $out);
        $this->assertMatchesRegularExpression('/app_key\s*\|\s*integration_secrets/', $out);
        $this->assertStringContainsString('перенос не начат', $out);
        $this->assertStringNotContainsString(self::SECRET, $out);
    }

    public function test_write_needs_confirmation_and_production_needs_the_flag(): void
    {
        $before = $this->target()->table('users')->count();
        [$code, $out] = $this->transfer(['--truncate-target' => true]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('Нет подтверждения', $out);
        [$code] = $this->transfer(['--truncate-target' => true, '--confirm-target' => 'wrong_database']);
        $this->assertSame(1, $code);
        $this->assertSame($before, $this->target()->table('users')->count());

        config(['app.env' => 'production']);
        [$code, $out] = $this->transfer(['--preflight' => true]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('--production', $out);
        $this->assertStringNotContainsString((string) getenv('TRANSFER_TARGET_URL'), $out);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, string}
     */
    private function transfer(array $options): array
    {
        $code = Artisan::call('db:transfer-to-mysql', $options + ['--no-interaction' => true]);

        return [$code, Artisan::output()];
    }

    /** A fresh target connection (each command run rebuilds the transfer connections). */
    private function target(): Connection
    {
        return TransferDatabases::connect(app('db'), config())->target;
    }

    /** One synthetic attachment, one encrypted secret and Unicode/JSON on the source, created once. */
    private function ensureFixtures(): void
    {
        if (DB::table('documents_files')->where('filename', 'transfer-fixture.pdf')->exists()) {
            return;
        }
        $employee = Employee::factory()->create(['full_name' => 'Марія Fixture 🙂', 'work_schedule' => ['days' => ['пн'], 'emoji' => '🙂']]);
        $document = Document::query()->create(['employee_id' => $employee->id, 'title' => 'Transfer fixture document', 'content_md' => 'Україна 🙂']);
        app(DatabaseDocumentStorage::class)->put($document, $this->pdf(), 'transfer-fixture.pdf');
        app(SecretVault::class)->put('transfer-fixture', 'transfer-fixture', self::SECRET);
    }

    private function pdf(): string
    {
        return self::PDF.str_repeat(' ', 4096);
    }
}
