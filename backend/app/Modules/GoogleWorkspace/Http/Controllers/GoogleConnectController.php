<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Http\Controllers;

use App\Models\User;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Services\GoogleConnectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Browser OAuth consent for Gmail / Calendar / Sheets (routes.web.php → 'web' group: the session keeps the state).
 * Superadmin only. Redirects back to the SPA with relative URLs (the API is served through the SPA origin).
 * Nothing secret goes to the URL or the log: only result codes.
 */
final class GoogleConnectController
{
    public const string SESSION_KEY = 'google_connect';

    private const string BACK = '/admin/integrations';

    public function __construct(
        private readonly GoogleConnectService $connect,
        private readonly LoggerInterface $log,
    ) {}

    /** GET /api/google/connect?services=gmail,calendar,sheets (default: all three). */
    public function redirect(Request $request): RedirectResponse
    {
        $services = GoogleService::parseList((string) $request->query('services', implode(',', GoogleService::values())));
        if ($services === []) {
            return $this->fail('invalid_services');
        }
        $state = Str::random(40);
        try {
            $url = $this->connect->authorizationUrl($services, $state);
        } catch (GoogleException $e) {
            return $this->fail($e->errorCode);
        }
        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'services' => array_map(static fn (GoogleService $s): string => $s->value, $services),
        ]);

        return new RedirectResponse($url);
    }

    /** GET /api/google/connect/callback?code=…&state=… */
    public function callback(Request $request): RedirectResponse
    {
        $saved = $request->session()->pull(self::SESSION_KEY);
        if ($request->query->has('error')) {
            return $this->fail('consent_denied');
        }
        $state = $request->query('state');
        $code = $request->query('code');
        if (! is_array($saved) || ! is_string($saved['state'] ?? null) || ! is_string($state)
            || ! hash_equals($saved['state'], $state) || ! is_string($code) || $code === '') {
            return $this->fail('invalid_state');
        }
        $requested = GoogleService::parseList(implode(',', array_filter((array) ($saved['services'] ?? []), 'is_string')));
        $actor = $request->user();
        assert($actor instanceof User);

        try {
            $result = $this->connect->complete($code, $requested, $actor);
        } catch (GoogleException $e) {
            return $this->fail($e->errorCode);
        }
        $query = ['connected' => 'google'];
        if ($result['missing'] !== []) {
            $query['missing'] = implode(',', array_map(static fn (GoogleService $s): string => $s->value, $result['missing']));
        }
        $this->log->info('google.connected', ['by' => $actor->id, 'services' => count($result['connected']), 'missing' => count($result['missing'])]);

        return new RedirectResponse(self::BACK.'?'.http_build_query($query));
    }

    private function fail(string $code): RedirectResponse
    {
        $this->log->warning('google.connect_failed', ['code' => $code]);

        return new RedirectResponse(self::BACK.'?'.http_build_query(['google_error' => $code]));
    }
}
