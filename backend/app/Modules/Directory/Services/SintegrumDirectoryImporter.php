<?php

declare(strict_types=1);

namespace App\Modules\Directory\Services;

use App\Models\User;
use App\Modules\Directory\Contracts\DictionaryRepository;
use App\Modules\Directory\Contracts\DirectoryImporter;
use App\Modules\Directory\DTO\ImportReport;
use App\Modules\Directory\DTO\MappedPayload;
use App\Modules\Directory\Enums\DictionaryType;
use App\Modules\Directory\Exceptions\DirectoryException;
use App\Modules\Directory\Support\SintegrumPayloadMapper;
use App\Modules\Integrations\Contracts\IntegrationRepository;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Definitions\SintegrumApiDefinition;
use App\Modules\Integrations\Enums\LogLevel;
use App\Modules\Integrations\Support\OutboundUrlGuard;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Imports branches, cities, departments and positions ("jobs") from the Sintegrum REST API.
 *
 * - Credentials: integration "sintegrum_api" (base_url setting + token secret via SecretVault).
 * - Requests: GET {base_url}/{cities|branches|departments|jobs}/list with "Authorization: Bearer <token>"
 *   (paths and header come from the Sintegrum apidoc; NOT verified against the live API).
 * - Everything is fetched first, then written in one transaction: a failed request changes nothing.
 * - Upsert by external_id, never deletes. Whole run is limited to TOTAL_SECONDS.
 * - Audit: integration_logs of sintegrum_api, with counts or an error code only (no URLs, tokens, bodies).
 */
final class SintegrumDirectoryImporter implements DirectoryImporter
{
    public const string INTEGRATION_KEY = 'sintegrum_api';

    public const int TOTAL_SECONDS = 60;

    private const int REQUEST_TIMEOUT_SECONDS = 15;

    private const int CONNECT_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly Http $http,
        private readonly SecretVault $vault,
        private readonly IntegrationRepository $integrations,
        private readonly OutboundUrlGuard $guard,
        private readonly DictionaryRepository $dictionaries,
        private readonly SintegrumPayloadMapper $mapper,
    ) {}

    public function import(User $actor): ImportReport
    {
        $token = $this->vault->get(self::INTEGRATION_KEY, 'token');
        if ($token === null || $token === '') {
            throw DirectoryException::integrationNotConfigured();
        }
        $integration = $this->integrations->findOrCreate(self::INTEGRATION_KEY);
        $base = rtrim($this->baseUrl($integration->settings), '/');

        try {
            $blocked = $this->guard->check($base);
            if ($blocked !== null) {
                throw DirectoryException::urlBlocked($blocked);
            }
            $deadline = microtime(true) + self::TOTAL_SECONDS;
            $payloads = [];
            foreach (DictionaryType::importOrder() as $type) {
                $payloads[$type->value] = $this->fetch($base.'/'.$type->sintegrumResource().'/list', $token, $deadline);
            }
            $report = $this->dictionaries->transaction(fn (): ImportReport => $this->store($payloads));
        } catch (DirectoryException $e) {
            $this->integrations->log($integration, LogLevel::Error, 'directory_import_failed', [
                'by' => $actor->id,
                'code' => $e->errorCode,
            ]);

            throw $e;
        }

        $this->integrations->log($integration, LogLevel::Info, 'directory_imported', [
            'by' => $actor->id,
            'counts' => $report->toArray(),
        ]);

        return $report;
    }

    /** @param  array<string, mixed>  $settings */
    private function baseUrl(array $settings): string
    {
        $base = $settings['base_url'] ?? null;

        return is_string($base) && $base !== '' ? $base : SintegrumApiDefinition::DEFAULT_BASE_URL;
    }

    private function fetch(string $url, string $token, float $deadline): MappedPayload
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw DirectoryException::timeout();
        }

        try {
            $response = $this->http
                ->withOptions(['allow_redirects' => false])
                ->withToken($token)
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(max(1, (int) min(self::REQUEST_TIMEOUT_SECONDS, ceil($remaining))))
                ->get($url);
        } catch (Throwable) {
            // Never the exception text: it may contain the URL or request headers.
            throw DirectoryException::unreachable();
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw DirectoryException::unauthorized();
        }
        if (! $response->successful()) {
            throw DirectoryException::httpError($response->status());
        }

        return $this->mapper->map($response->json()) ?? throw DirectoryException::badResponse();
    }

    /** @param  array<string, MappedPayload>  $payloads */
    private function store(array $payloads): ImportReport
    {
        $report = new ImportReport;
        foreach (DictionaryType::importOrder() as $type) {
            $counts = $report->for($type);
            $payload = $payloads[$type->value];
            $counts->skipped += $payload->invalid;
            foreach ($payload->items as $item) {
                $counts->add($this->dictionaries->upsert($type, $item));
            }
        }

        return $report;
    }
}
