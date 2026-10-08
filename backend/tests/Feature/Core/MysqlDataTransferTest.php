<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Services\Transfer\MysqlCollationKeys;
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

    /**
     * Measured on MySQL 8.4 (utf8mb4_0900_ai_ci): case and Latin accents collide, "Й" and "И" do NOT (Й has its own
     * primary weight in UCA 9.0) — the preflight reports what the server itself decides, via WEIGHT_STRING.
     */
    public function test_preflight_blocks_unique_collisions_without_printing_values(): void
    {
        $emails = [
            'collide.transfer@example.test', 'COLLIDE.transfer@example.test',
            'jose.transfer@example.test', 'josé.transfer@example.test',
            'йосип.transfer@example.test', 'иосип.transfer@example.test',
        ];
        $ids = array_map(fn (string $email): int => User::factory()->create(['email' => $email])->id, $emails);
        try {
            [$code, $out] = $this->transfer(['--preflight' => true]);
            $this->assertSame(1, $code, $out);
            $this->assertMatchesRegularExpression('/unique_collision\s*\|\s*users\s*\|\s*индекс users_email_unique \(email\): 2 групп/u', $out);
            $this->assertStringContainsString("[{$ids[0]}, {$ids[1]}]", $out);
            $this->assertStringContainsString("[{$ids[2]}, {$ids[3]}]", $out);
            $this->assertStringNotContainsString("[{$ids[4]}, {$ids[5]}]", $out);
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

    /** Facts behind ADR 0010 "Отличия MySQL": which letter pairs utf8mb4_0900_ai_ci treats as equal (keys from the server). */
    public function test_mysql_collation_keys_for_cyrillic_and_latin_pairs(): void
    {
        $keys = new MysqlCollationKeys($this->target());
        $equal = fn (string $a, string $b): bool => count(array_unique($keys->keys('utf8mb4_0900_ai_ci', [$a, $b]))) === 1;

        $this->assertTrue($equal('a@x.test', 'A@x.test'));
        $this->assertTrue($equal('jose', 'josé'));
        $this->assertFalse($equal('Йосип', 'Иосип'));
        $this->assertFalse($equal('trailing ', 'trailing'), 'NO PAD: a trailing space is significant');
        fwrite(STDERR, sprintf(
            "collation facts: ё=е %s, ї=і %s, Ї=І %s, ґ=г %s\n",
            var_export($equal('ёж', 'еж'), true), var_export($equal('їжак', 'іжак'), true),
            var_export($equal('Їжак', 'Іжак'), true), var_export($equal('ґанок', 'ганок'), true),
        ));
    }

    public function test_transfer_is_idempotent_resumable_and_keeps_attachments_and_ciphertexts(): void
    {
        [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('ИТОГ: OK', $out);

        [$code, $out] = $this->transfer(['--confirm-target' => self::TARGET_DB]);
        $this->assertSame(0, $code, $out);
        $this->assertMatchesRegularExpression('/^Скопировано строк: 0$/mu', $out);
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
        $this->assertMatchesRegularExpression('/^Скопировано строк: '.$removed.'$/mu', $out);
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

        [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);
        $this->assertSame(0, $code, $out); // leaves the target equal to the source for the CLI steps
    }

    public function test_target_rows_foreign_to_the_source_block_a_run_without_truncate(): void
    {
        [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);
        $this->assertSame(0, $code, $out);
        $target = $this->target();
        $row = (array) $target->table('users')->orderBy('id')->first();
        $original = $row;
        $row['id'] = 999999;
        $row['email'] = 'foreign.row@example.test';
        $target->table('users')->insert($row);

        [$code, $out] = $this->transfer(['--confirm-target' => self::TARGET_DB]);
        $this->assertSame(1, $code, $out);
        $this->assertMatchesRegularExpression('/target_not_empty\s*\|\s*users\s*\|\s*в цели 1 строк с ключами, которых нет в источнике/u', $out);
        $this->assertStringContainsString('перенос не начат', $out);
        $this->assertStringNotContainsString('foreign.row@example.test', $out);
        $this->assertSame(1, $this->target()->table('users')->where('id', 999999)->count(), 'nothing written');

        $this->target()->table('users')->where('id', 999999)->delete();
        $this->target()->table('users')->where('id', $original['id'])->update(['name' => 'Changed On Target']);
        [$code, $out] = $this->transfer(['--confirm-target' => self::TARGET_DB]);
        $this->assertSame(1, $code, $out);
        $this->assertMatchesRegularExpression('/target_not_empty\s*\|\s*users\s*\|\s*в цели 1 строк отличаются от источника/u', $out);

        [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('ИТОГ: OK', $out);
    }

    public function test_verify_fails_on_schema_drift(): void
    {
        [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);
        $this->assertSame(0, $code, $out);
        $target = $this->target();
        try {
            $target->statement('ALTER TABLE `users` ADD COLUMN `drift_probe` int NULL');
            $target->statement('CREATE TABLE `zz_drift_probe` (`id` int PRIMARY KEY)');
            $target->table('migrations')->insert(['migration' => '2099_01_01_000000_drift_probe', 'batch' => 99]);

            [$code, $out] = $this->transfer(['--verify' => true]);
            $this->assertSame(1, $code, $out);
            $this->assertStringContainsString('ИТОГ: FAIL', $out);
            $this->assertMatchesRegularExpression('/schema\s*\|\s*users\s*\|\s*колонка users\.drift_probe/u', $out);
            $this->assertMatchesRegularExpression('/schema\s*\|\s*zz_drift_probe\s*\|\s*таблица есть только в цели/u', $out);
            $this->assertMatchesRegularExpression('/schema\s*\|\s*migrations\s*\|\s*версии схемы расходятся/u', $out);
        } finally {
            $target = $this->target();
            $target->statement('ALTER TABLE `users` DROP COLUMN `drift_probe`');
            $target->statement('DROP TABLE IF EXISTS `zz_drift_probe`');
            $target->table('migrations')->where('migration', '2099_01_01_000000_drift_probe')->delete();
        }
        [$code, $out] = $this->transfer(['--verify' => true]);
        $this->assertSame(0, $code, $out);
    }

    /** The app's own MySQL connection pointing at the target through loopback aliases or a socket is refused. */
    public function test_target_equal_to_the_app_database_is_refused_through_aliases_and_sockets(): void
    {
        $base = (array) config('database.connections.mysql');
        $app = ['url' => null, 'port' => 3306, 'database' => self::TARGET_DB, 'username' => 'app', 'password' => 'app', 'unix_socket' => ''];
        $cases = [
            'localhost' => ['host' => 'localhost'],
            '127.0.0.1' => ['host' => '127.0.0.1'],
            '::1' => ['host' => '::1'],
            'socket' => ['host' => 'localhost', 'unix_socket' => '/var/run/mysqld/mysqld.sock'],
            'hostname' => ['host' => (string) gethostname()],
        ];
        $before = $this->target()->table('users')->count();
        foreach ($cases as $name => $override) {
            config(['database.default' => 'mysql', 'database.connections.mysql' => array_merge($base, $app, $override)]);
            DB::purge('mysql');
            [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);
            $this->assertSame(1, $code, "{$name}: {$out}");
            $this->assertMatchesRegularExpression('/перенос (в живую базу )?запрещён/u', $out, $name);
        }
        $this->assertSame($before, $this->target()->table('users')->count(), 'nothing truncated');
    }

    /** Fail-closed: the app uses MySQL but its connection cannot be compared (here: nothing listens on 3307). */
    public function test_app_mysql_connection_that_cannot_be_compared_refuses_the_write(): void
    {
        $before = $this->target()->table('users')->count();
        config(['database.default' => 'mysql', 'database.connections.mysql' => array_merge((array) config('database.connections.mysql'), [
            'url' => null, 'host' => '127.0.0.1', 'port' => 3307, 'database' => 'app_other', 'username' => 'app', 'password' => 'app', 'unix_socket' => '',
        ])]);
        DB::purge('mysql');

        $this->assertSame(TransferDatabases::APP_UNKNOWN, TransferDatabases::connect(app('db'), config())->appServerVerdict(DB::connection()));
        [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('Не удалось сравнить цель с MySQL-подключением приложения', $out);
        $this->assertStringNotContainsString((string) getenv('TRANSFER_TARGET_URL'), $out);
        $this->assertSame($before, $this->target()->table('users')->count(), 'nothing truncated');
    }

    /** Before the cutover the app is on PostgreSQL: the server comparison is skipped and the transfer runs. */
    public function test_app_on_pgsql_skips_the_server_comparison(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame(TransferDatabases::APP_NOT_MYSQL, TransferDatabases::connect(app('db'), config())->appServerVerdict(DB::connection()));

        [$code, $out] = $this->transfer(['--truncate-target' => true, '--confirm-target' => self::TARGET_DB]);

        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('Не удалось сравнить', $out);
        $this->assertStringContainsString('ИТОГ: OK', $out);
    }

    public function test_target_through_a_unix_socket_is_refused_and_the_app_socket_is_not_inherited(): void
    {
        config(['db_transfer.target_url' => getenv('TRANSFER_TARGET_URL').'?unix_socket=/var/run/mysqld/mysqld.sock']);
        [$code, $out] = $this->transfer(['--preflight' => true]);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('unix_socket не поддерживается', $out);

        // DB_SOCKET of the app (mysql connection) must not redirect the target away from the URL host.
        config(['db_transfer.target_url' => getenv('TRANSFER_TARGET_URL'), 'database.connections.mysql.unix_socket' => '/nonexistent/mysqld.sock']);
        [$code, $out] = $this->transfer(['--verify' => true]);
        $this->assertStringNotContainsString('Остановлено', $out);
        $this->assertStringContainsString('ИТОГ:', $out);
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
