<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\GoogleWorkspace\Contracts\SheetImportRepository;
use App\Modules\GoogleWorkspace\Contracts\SheetsClient;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Enums\SheetField;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Models\SheetImport;
use App\Modules\GoogleWorkspace\Support\SheetRange;
use App\Modules\Integrations\Enums\LogLevel;
use App\Modules\Recruiting\Contracts\VacancyRepository;
use App\Modules\Recruiting\DTO\CandidateData;
use App\Modules\Recruiting\Exceptions\RecruitingException;
use App\Modules\Recruiting\Services\CandidateService;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Google Sheets → candidates: read the header row, suggest a column mapping, preview, import with dedupe
 * (CandidateService::createOrMatch) and remember mapping + last imported row for incremental re-sync.
 * Reports never contain cell values — only counts and {row, code}.
 */
final readonly class SheetsImportService
{
    public const int PREVIEW_ROWS = 10;

    public const int BATCH_ROWS = 500;

    public const int MAX_ERRORS = 100;

    /** Budget of one run; the Vercel function lives 60 s. */
    public const int TOTAL_SECONDS = 40;

    public function __construct(
        private SheetsClient $sheets,
        private SheetImportRepository $imports,
        private CandidateService $candidates,
        private VacancyRepository $vacancies,
        private GoogleConnectionStore $connections,
        private LoggerInterface $log,
    ) {}

    /**
     * Header row + the first 10 data rows + a suggested mapping.
     *
     * @return array{spreadsheet_id: string, sheet: string, headers: list<string>, rows: list<list<string>>, suggested: array<string, int>}
     *
     * @throws GoogleException
     */
    public function inspect(string $url, string $sheet): array
    {
        $id = SheetRange::spreadsheetId($url) ?? throw GoogleException::invalidSheetUrl();
        $rows = $this->sheets->values($id, SheetRange::rows($sheet, 1, 1 + self::PREVIEW_ROWS));
        $headers = $rows[0] ?? [];

        return [
            'spreadsheet_id' => $id,
            'sheet' => $sheet,
            'headers' => $headers,
            'rows' => array_slice($rows, 1),
            'suggested' => self::suggest($headers),
        ];
    }

    /**
     * Saves (or updates) the import by (spreadsheet, sheet) and runs it right away.
     *
     * @param  array<string, int>  $mapping
     * @return array{import: SheetImport, report: array<string, mixed>}
     *
     * @throws GoogleException
     */
    public function saveAndRun(User $actor, string $url, string $sheet, array $mapping, bool $autoSync): array
    {
        $inspected = $this->inspect($url, $sheet);
        $mapping = self::cleanMapping($mapping, count($inspected['headers']));
        $import = $this->imports->upsert($inspected['spreadsheet_id'], $sheet, [
            'headers' => $inspected['headers'],
            'mapping' => $mapping,
            'auto_sync' => $autoSync,
            'created_by' => $actor->id,
        ]);

        return ['import' => $import, 'report' => $this->run($import, $actor)];
    }

    /**
     * Imports the rows after last_row (at most BATCH_ROWS per run) and moves last_row forward.
     *
     * @return array<string, mixed>
     *
     * @throws GoogleException
     */
    public function run(SheetImport $import, ?User $actor): array
    {
        $deadline = microtime(true) + self::TOTAL_SECONDS;
        $start = max(2, $import->last_row + 1);
        $rows = $this->sheets->values($import->spreadsheet_id, SheetRange::rows($import->sheet, $start, $start + self::BATCH_ROWS - 1));
        $report = ['created' => 0, 'matched' => 0, 'skipped' => 0, 'applied' => 0, 'vacancy_unmatched' => 0, 'errors' => []];
        $last = $import->last_row;

        foreach ($rows as $i => $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            $number = $start + $i;
            $this->importRow($actor, $import->mapping, $row, $number, $report);
            $last = $number;
        }

        $report['last_row'] = $last;
        $report['rows'] = $last - $import->last_row;
        $this->imports->update($import, ['last_row' => $last, 'last_synced_at' => Carbon::now(), 'last_report' => $report]);
        $this->connections->log(GoogleService::Sheets, LogLevel::Info, 'sheets_imported', [
            'import' => $import->id,
            'by' => $actor?->id,
            'created' => $report['created'],
            'matched' => $report['matched'],
            'skipped' => $report['skipped'],
            'errors' => count($report['errors']),
        ]);

        return $report;
    }

    /**
     * Header → field by exact name first, then by a contained hint (utm/telegram/e-mail before phone and name).
     *
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    public static function suggest(array $headers): array
    {
        $normalized = array_map(static fn (string $h): string => mb_strtolower(trim($h)), $headers);
        $mapping = [];
        foreach (SheetField::cases() as $field) {
            foreach ($normalized as $index => $header) {
                if (in_array($header, $field->hints(), true) && ! in_array($index, $mapping, true)) {
                    $mapping[$field->value] = $index;
                    break;
                }
            }
        }
        $order = [SheetField::UtmSource, SheetField::UtmMedium, SheetField::UtmCampaign, SheetField::UtmContent, SheetField::UtmTerm,
            SheetField::Telegram, SheetField::Email, SheetField::Phone, SheetField::FullName, SheetField::Source,
            SheetField::Vacancy, SheetField::CreatedAt];
        foreach ($order as $field) {
            if (isset($mapping[$field->value])) {
                continue;
            }
            foreach ($normalized as $index => $header) {
                if (in_array($index, $mapping, true)) {
                    continue;
                }
                foreach ($field->hints() as $hint) {
                    if (mb_strlen($hint) >= 3 && str_contains($header, $hint)) {
                        $mapping[$field->value] = $index;

                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Known fields with a column index inside the header only.
     *
     * @param  array<string, int>  $mapping
     * @return array<string, int>
     */
    public static function cleanMapping(array $mapping, int $columns): array
    {
        $clean = [];
        foreach ($mapping as $field => $index) {
            if (SheetField::tryFrom($field) !== null && $index >= 0 && $index < max($columns, 1)) {
                $clean[$field] = $index;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, int>  $mapping
     * @param  list<string>  $row
     * @param  array<string, mixed>  $report
     */
    private function importRow(?User $actor, array $mapping, array $row, int $number, array &$report): void
    {
        $cell = static fn (SheetField $f): ?string => isset($mapping[$f->value]) && ($row[$mapping[$f->value]] ?? '') !== ''
            ? $row[$mapping[$f->value]] : null;
        if (implode('', $row) === '') {
            $report['skipped']++;

            return;
        }
        $utm = [];
        foreach (SheetField::cases() as $field) {
            if ($field->isUtm() && $cell($field) !== null) {
                $utm[substr($field->value, 4)] = (string) $cell($field);
            }
        }
        $data = CandidateData::fromArray([
            'full_name' => $cell(SheetField::FullName),
            'phone' => $cell(SheetField::Phone),
            'email' => $cell(SheetField::Email),
            'telegram' => $cell(SheetField::Telegram),
            'source' => mb_strtolower((string) $cell(SheetField::Source)),
            'utm' => $utm === [] ? null : $utm,
        ]);
        $vacancy = null;
        $title = $cell(SheetField::Vacancy);
        if ($title !== null) {
            $vacancy = $this->vacancies->findOpenByTitle($title);
            if ($vacancy === null) {
                $report['vacancy_unmatched']++;
            }
        }

        try {
            $match = $this->candidates->createOrMatch($actor, $data, $vacancy, self::date($cell(SheetField::CreatedAt)));
            $report[$match->created ? 'created' : 'matched']++;
            if ($match->applicationCreated) {
                $report['applied']++;
            }
        } catch (RecruitingException $e) {
            if ($e->errorCode === 'no_contacts') {
                $report['skipped']++;
            } else {
                $this->error($report, $number, $e->errorCode);
            }
        } catch (Throwable $e) {
            // Class only: the message may contain cell values (personal data).
            $this->log->warning('google.sheets_row_failed', ['row' => $number, 'exception' => $e::class]);
            $this->error($report, $number, 'row_failed');
        }
    }

    /** @param  array<string, mixed>  $report */
    private function error(array &$report, int $row, string $code): void
    {
        if (count($report['errors']) < self::MAX_ERRORS) {
            $report['errors'][] = ['row' => $row, 'code' => $code];
        }
    }

    private static function date(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $date->isFuture() ? null : $date;
    }
}
