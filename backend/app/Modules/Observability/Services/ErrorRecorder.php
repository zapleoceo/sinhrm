<?php

declare(strict_types=1);

namespace App\Modules\Observability\Services;

use App\Modules\Integrations\Support\SecretScrubber;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes errors into error_events, grouped by fingerprint (source + class + file + line): a repeat bumps the counter
 * and last_seen_at and reopens a resolved group. Never throws: a failure (DB down, …) goes to stderr as one JSON line,
 * so the exception reporter that calls this can never break a response or loop on itself.
 */
final class ErrorRecorder
{
    public const string SOURCE_SERVER = 'server';

    public const string SOURCE_WEB = 'web';

    private const int MAX_MESSAGE = 1000;

    private bool $recording = false;

    public function __construct(private readonly Application $app, private readonly SecretScrubber $scrubber) {}

    /** Unhandled server exception (the exception reporter in bootstrap/app.php). */
    public function recordException(Throwable $e): void
    {
        $this->guard(function () use ($e): void {
            $route = null;
            $userId = null;
            $request = $this->app->bound('request') ? $this->app->make('request') : null;
            if ($request instanceof Request) {
                $route = $request->route()?->getName() ?? $request->route()?->uri();
                $id = $request->user()?->getAuthIdentifier();
                $userId = is_numeric($id) ? (int) $id : null;
            }
            $this->store(self::SOURCE_SERVER, $e::class, $e->getMessage(), $this->relativeFile($e->getFile()), $e->getLine(), $route, $userId);
        });
    }

    /** A client-side error (JS exception or a 5xx seen by the SPA), already validated by the request. */
    public function recordClient(string $kind, string $message, ?string $location, ?string $route, ?int $userId): void
    {
        $this->guard(fn () => $this->store(self::SOURCE_WEB, $kind, $message, $location, null, $route, $userId));
    }

    /** Secrets (SecretScrubber), then emails and long digit runs (phones, document numbers) are masked. */
    public function clean(string $message): string
    {
        $text = $this->scrubber->scrub($message);
        $text = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text);
        $text = (string) preg_replace('/\+?\d[\d\s()-]{7,}\d/', '[number]', $text);

        return mb_substr($text, 0, self::MAX_MESSAGE);
    }

    private function store(string $source, string $class, string $message, ?string $file, ?int $line, ?string $route, ?int $userId): void
    {
        $now = Carbon::now();
        $clean = $this->clean($message);
        $route = $route === null ? null : mb_substr($route, 0, 255);
        $row = [
            'fingerprint' => hash('sha256', implode('|', [$source, $class, (string) $file, (string) $line])),
            'source' => $source,
            'exception_class' => mb_substr($class, 0, 255),
            'message' => $clean,
            'file' => $file === null ? null : mb_substr($file, 0, 500),
            'line' => $line,
            'route' => $route,
            'last_user_id' => $userId,
            'count' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'resolved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        // One statement: a new group, or bump the existing one (and reopen it if it was resolved).
        DB::table('error_events')->upsert([$row], ['fingerprint'], [
            'count' => DB::raw('error_events.count + 1'),
            'message' => $clean,
            'route' => $route,
            'last_user_id' => $userId,
            'last_seen_at' => $now,
            'resolved_at' => null,
            'updated_at' => $now,
        ]);
    }

    private function relativeFile(string $file): string
    {
        $base = rtrim(str_replace('\\', '/', $this->app->basePath()), '/').'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    private function guard(callable $write): void
    {
        if ($this->recording) {
            return; // an error while recording an error: never recurse
        }
        $this->recording = true;
        try {
            $write();
        } catch (Throwable $failure) {
            @file_put_contents('php://stderr', json_encode(['level' => 'error', 'message' => 'error_log.record_failed', 'exception' => $failure::class]).PHP_EOL);
        } finally {
            $this->recording = false;
        }
    }
}
