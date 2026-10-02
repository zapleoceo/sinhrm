<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Module boundaries (docs/architecture/overview.md, "Границы модулей"): a module talks to another module through its
 * Contracts (and DTO, Enums, Events), never by importing its Models, Services, Repositories or Http classes directly.
 * Core is the shared kernel and may be imported by anyone.
 *
 * Existing violations are frozen in module-boundaries-baseline.php and the known two-way module dependencies in
 * KNOWN_CYCLES: a NEW violation or cycle fails, and a fixed one must be removed from the list, so both only shrink.
 */
final class ModuleBoundariesTest extends TestCase
{
    /** Layers of another module that are internal to it. */
    private const string INTERNAL = 'Models|Services|Repositories|Http';

    /** Allowed everywhere: the auth middleware every module's routes are behind. */
    private const array ALLOWED = ['App\Modules\Auth\Http\Middleware\EnsureUserIsActive'];

    /**
     * Two-way dependencies between modules (any import, Contracts included), alphabetical pairs. Baseline of 2026-10-02.
     *
     * @var list<string>
     */
    private const array KNOWN_CYCLES = [
        'Audit ↔ People', // People's "History" tab uses Audit's request/resource; Audit tracks People models
        'Audit ↔ Recruiting', // the same for candidates
        'Auth ↔ Core', // Core uses Auth\Enums\UserRole (module access, role gates)
        'Channels ↔ Recruiting', // OfferService sends through Channels\Services\MessageService
        'Core ↔ Pulse', // Core\Services\Demo\DemoDataService seeds Pulse
        'Core ↔ Recruiting', // the same for Recruiting
        'Recruiting ↔ Scripts', // CareerSiteService and OfferService create Scripts tasks
    ];

    public function test_no_new_direct_imports_of_another_modules_internals(): void
    {
        $found = $this->violations();
        /** @var array<string, list<string>> $baseline */
        $baseline = require __DIR__.'/module-boundaries-baseline.php';

        $new = [];
        foreach ($found as $file => $imports) {
            foreach (array_diff($imports, $baseline[$file] ?? []) as $import) {
                $new[] = "{$file}: {$import}";
            }
        }
        $fixed = [];
        foreach ($baseline as $file => $imports) {
            foreach (array_diff($imports, $found[$file] ?? []) as $import) {
                $fixed[] = "{$file}: {$import}";
            }
        }

        $this->assertSame([], $new, "A module imports another module's internals. Depend on its Contracts instead:\n".implode("\n", $new));
        $this->assertSame([], $fixed, "Fixed — delete these entries from tests/Unit/Core/module-boundaries-baseline.php:\n".implode("\n", $fixed));
    }

    public function test_no_new_two_way_dependencies_between_modules(): void
    {
        $uses = $this->moduleGraph();
        $cycles = [];
        foreach ($uses as $from => $targets) {
            foreach ($targets as $to) {
                if ($from < $to && in_array($from, $uses[$to] ?? [], true)) {
                    $cycles[] = "{$from} ↔ {$to}";
                }
            }
        }
        sort($cycles);
        $known = self::KNOWN_CYCLES;
        sort($known);

        $this->assertSame([], array_values(array_diff($cycles, $known)), 'New two-way dependency between modules.');
        $this->assertSame([], array_values(array_diff($known, $cycles)), 'Cycle is gone — delete it from KNOWN_CYCLES.');
    }

    public function test_the_scan_sees_the_modules(): void
    {
        // Guards against a silently empty scan (wrong path or pattern): there are hundreds of module files.
        $this->assertGreaterThan(500, count($this->files()));
        $this->assertGreaterThan(20, count($this->moduleGraph()));
    }

    /** @return array<string, list<string>> file (relative to app/Modules) → imported internals of other modules */
    private function violations(): array
    {
        $found = [];
        foreach ($this->files() as $relative => $source) {
            $module = explode('/', $relative)[0];
            preg_match_all('/^use (App\\\\Modules\\\\(\w+)\\\\(?:'.self::INTERNAL.')\\\\[\w\\\\]+)(?: as \w+)?;/m', $source, $m, PREG_SET_ORDER);
            foreach ($m as [, $class, $target]) {
                if ($target !== $module && $target !== 'Core' && ! in_array($class, self::ALLOWED, true)) {
                    $found[$relative][] = $class;
                }
            }
        }

        foreach ($found as $file => $imports) {
            $found[$file] = array_values(array_unique($imports));
        }

        return $found;
    }

    /** @return array<string, list<string>> module → modules it imports anything from */
    private function moduleGraph(): array
    {
        $graph = [];
        foreach ($this->files() as $relative => $source) {
            $module = explode('/', $relative)[0];
            preg_match_all('/^use App\\\\Modules\\\\(\w+)\\\\/m', $source, $m);
            foreach ($m[1] as $target) {
                if ($target !== $module) {
                    $graph[$module][$target] = true;
                }
            }
        }

        $modules = [];
        foreach ($graph as $module => $targets) {
            $modules[$module] = array_keys($targets);
        }

        return $modules;
    }

    /** @return array<string, string> path relative to app/Modules (with "/") → source */
    private function files(): array
    {
        $root = dirname(__DIR__, 3).'/app/Modules';
        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}
