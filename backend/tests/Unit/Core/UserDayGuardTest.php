<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guard against the "day / week / year by UTC instead of Kyiv" class of bugs (docs/guides/development.md, "Ночное
 * окно"). The database and $now keep UTC moments; a calendar day, week, month or year the user sees comes from
 * Core\Support\UserTime (today(), now(), endOfDay(), wallTime()). Between 00:00 Kyiv and 00:00 UTC (21:00/22:00–24:00
 * UTC) a UTC date is still yesterday — a check-in, a week boundary, an accrual year would land on the wrong day.
 *
 * The test fails on a NEW calendar operation taken straight from the UTC clock in backend/app:
 *   Carbon::today()/yesterday()/tomorrow(), today(), Carbon::now()/now() followed by startOf…/endOf…/toDateString/
 *   year/month/dayOfWeek/isoWeekday/format('Y…'), and the same on $now (the UTC "current moment" by convention).
 * Moments stay UTC (created_at, retention cut-offs, SLA in hours, "24 hours ago") — they are not matched.
 * A deliberate UTC calendar use goes into ALLOWED with the reason; a fixed one must be removed (the list only shrinks).
 */
final class UserDayGuardTest extends TestCase
{
    private const string CALENDAR = '(?:copy\(\)\s*->\s*)?(?:(?:startOf|endOf)(?:Day|Week|IsoWeek|Month|Quarter|Year)\b|toDateString|year\b|month\b|day\b|dayOfWeek\w*|isoWeekday|isoFormat|format\(\s*[\'"][^\'"]*[YmdlDNjnW])';

    /**
     * "<path under app/>: <trimmed line>" => reason. The line itself, not its number: unrelated edits do not break it.
     *
     * @var array<string, string>
     */
    private const array ALLOWED = [
        'Modules/Ai/Services/AiAdminService.php: $today = Carbon::now(\'UTC\')->startOfDay();' => 'AI usage stats are per UTC day, the same day as the provider daily caps (docs/modules/ai.md)',
        'Modules/Ai/Services/AiBudget.php: return Carbon::now(\'UTC\')->startOfDay();' => 'AI daily caps reset at 00:00 UTC (provider-style daily budget, docs/modules/ai.md)',
        'Modules/Overview/Services/DayRouteService.php: $from = $now->copy()->startOfDay();' => '$now here is already UserTime::now() (DashboardService passes the user-zone moment)',
        'Modules/Overview/Services/DayRouteService.php: $to = $now->copy()->endOfDay();' => 'the same: user-zone $now',
        'Modules/Scripts/Repositories/EloquentTaskRepository.php: $dayStart = UserTime::toStorage($now->copy()->startOfDay());' => '$now is UserTime::now() of TaskService (the user\'s day), converted back to storage',
        'Modules/Scripts/Repositories/EloquentTaskRepository.php: $dayEnd = UserTime::toStorage($now->copy()->endOfDay());' => 'the same',
    ];

    public function test_no_new_calendar_day_taken_from_the_utc_clock(): void
    {
        $found = $this->utcCalendarLines();
        $new = array_values(array_diff(array_keys($found), array_keys(self::ALLOWED)));
        $fixed = array_values(array_diff(array_keys(self::ALLOWED), array_keys($found)));

        $this->assertSame([], $new, 'A calendar day/week/month/year is taken from the UTC clock. Use Core\\Support\\UserTime '
            ."(today(\$now), now(\$now), endOfDay(), wallTime()) or, if UTC is intended, add the line to ALLOWED with the reason:\n".implode("\n", $new));
        $this->assertSame([], $fixed, "Fixed — delete these entries from UserDayGuardTest::ALLOWED:\n".implode("\n", $fixed));
    }

    public function test_the_guard_catches_the_known_shapes(): void
    {
        $bad = [
            '$d = Carbon::today();',
            '$d = CarbonImmutable::today();',
            '$d = today();',
            '$d = Carbon::now()->startOfDay();',
            '$d = now()->toDateString();',
            '$y = Carbon::now()->year;',
            "\$p = \$now->format('Y-m');",
            '$w = $now->copy()->startOfWeek();',
            "'submitted_on' => (\$now ?? Carbon::now())->toDateString(),",
            '$d = $now->dayOfWeekIso;',
        ];
        $ok = [
            '$d = UserTime::today($now);',
            "'created_at' => Carbon::now(),",
            '$before = $now->copy()->subDays(30);',
            '$moment = Carbon::now()->addSeconds(5);',
            '$x = $request->now()->toDateString();',
            'public function today(User $user): array',
        ];
        foreach ($bad as $line) {
            $this->assertTrue(self::isCalendarFromUtc($line), $line);
        }
        foreach ($ok as $line) {
            $this->assertFalse(self::isCalendarFromUtc($line), $line);
        }
    }

    private static function isCalendarFromUtc(string $line): bool
    {
        $c = self::CALENDAR;
        $patterns = [
            '/\bCarbon(?:Immutable)?::(?:today|yesterday|tomorrow)\(/',
            '/(?<![\w>:$])today\(\s*\)/',
            '/(?<![\w>:$])now\(\s*\)\s*->\s*'.$c.'/',
            '/\bCarbon(?:Immutable)?::now\([^)]*\)\s*\)?\s*->\s*'.$c.'/',
            '/\$now\s*\??->\s*'.$c.'/',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> "<path>: <trimmed line>" of every match in backend/app (UserTime itself excluded) */
    private function utcCalendarLines(): array
    {
        $root = dirname(__DIR__, 3).'/app';
        $found = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($path === 'Modules/Core/Support/UserTime.php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (self::isCalendarFromUtc($trimmed)) {
                    $found[$path.': '.$trimmed] = true;
                }
            }
        }
        ksort($found);

        return $found;
    }
}
