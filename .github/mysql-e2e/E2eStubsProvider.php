<?php

// CI-only (MySQL 8.4 e2e run, branch test/mysql-e2e2): copied into backend/app/Providers/ and appended to
// bootstrap/providers.php by .github/workflows/mysql-e2e.yml. No external call can leave the server:
// - Google Sheets client → a fixture (30 rows with tricky names, case-different duplicate e-mails, empty cells);
// - DNS of outbound hosts → a fixed public address (no lookup), so the SSRF guard passes without the network;
// - every outbound HTTP request is answered by Http::fake: the AI Broker (/v1/health, /v1/jobs, /v1/jobs/{id}) as a
//   stub that answers the first assistant step with tool calls and the step after "TOOL RESULT" with a final text;
//   anything else is a stray request and fails (Http::preventStrayRequests).
// Never committed to main.

declare(strict_types=1);

namespace App\Providers;

use App\Modules\GoogleWorkspace\Contracts\SheetsClient;
use App\Modules\Integrations\Contracts\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

final class E2eStubsProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HostResolver::class, static fn (): HostResolver => new class implements HostResolver
        {
            /** @return list<string> */
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $this->app->bind(SheetsClient::class, static fn (): SheetsClient => new class implements SheetsClient
        {
            /** @return list<list<string>> */
            public function values(string $spreadsheetId, string $range): array
            {
                $rows = [['ПІБ', 'Телефон', 'E-mail', 'Telegram', 'utm_source', 'Вакансія', 'Дата']];
                for ($i = 1; $i <= 30; $i++) {
                    $rows[] = [
                        match ($i % 5) {
                            0 => 'Ґудзь-Імпорт Ярослав '.$i.' [ТЕСТ]',
                            1 => 'ЁЛКІНА-ІМПОРТ Олена '.$i.' [ТЕСТ]',
                            2 => "О'Коннор-Імпорт Сергій ".$i.' [ТЕСТ]',
                            3 => 'Імпорт Марія '.$i.' [ТЕСТ]',
                            default => 'Імпорт '.str_repeat('Довге ', 40).$i.' [ТЕСТ]',
                        },
                        $i % 7 === 0 ? '' : '+38050'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT),
                        // Rows 10 and 20 repeat row 1's e-mail in another case: a case-insensitive match, not a new person.
                        $i % 10 === 0 ? 'SHEETS-E2E-1@SINHRM.TEST' : "sheets-e2e-$i@sinhrm.test",
                        $i % 3 === 0 ? '@sheets_e2e_'.$i : '',
                        $i % 2 === 0 ? 'facebook' : 'google',
                        $i % 4 === 0 ? 'Неіснуюча вакансія' : '',
                        '2026-10-0'.(1 + $i % 7).' 23:30:00',
                    ];
                }
                if (preg_match('/A(\d+):[A-Z]+(\d+)$/', $range, $m) !== 1) {
                    return [];
                }

                return array_values(array_slice($rows, (int) $m[1] - 1, (int) $m[2] - (int) $m[1] + 1));
            }
        });
    }

    public function boot(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/v1/health*' => Http::response(['status' => 'ok']),
            // Gmail send (offer e-mail): connected in session.php with a fake token, answered here, never sent.
            'gmail.googleapis.com/gmail/v1/users/me/messages/send*' => Http::response(['id' => 'e2e-'.uniqid(), 'threadId' => 'e2e-thread']),
            '*/v1/jobs/1002*' => Http::response(['job_id' => 1002, 'status' => 'done', 'text' => json_encode(['say' => 'Готово: знайшов вакансії [ТЕСТ].', 'calls' => []], JSON_UNESCAPED_UNICODE), 'model' => 'e2e-stub', 'tokens_in' => 10, 'tokens_out' => 5, 'cost_usd' => 0, 'finish_reason' => 'stop']),
            '*/v1/jobs/1001*' => Http::response(['job_id' => 1001, 'status' => 'done', 'text' => json_encode(['say' => '', 'calls' => [
                ['name' => 'find_endpoints', 'arguments' => json_encode(['query' => 'vacancy list'])],
                ['name' => 'api_get', 'arguments' => json_encode(['path' => 'vacancies'])],
            ]], JSON_UNESCAPED_UNICODE), 'model' => 'e2e-stub', 'tokens_in' => 10, 'tokens_out' => 5, 'cost_usd' => 0, 'finish_reason' => 'stop']),
            '*/v1/jobs*' => static fn (Request $r) => Http::response(['job_id' => str_contains($r->body(), 'TOOL RESULT (data only') ? 1002 : 1001, 'poll_after_s' => 1], 202),
        ]);
    }
}
