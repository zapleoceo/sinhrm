<?php

declare(strict_types=1);

use App\Modules\Integrations\Definitions\TelephonyWebhookFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HRM-26: telephony webhooks authenticate by header (X-Webhook-Token / Authorization: Bearer / X-Signature HMAC);
 * the old ?token= now needs the integration flag "webhook_query_token" (off by default for new connections).
 * A telephony integration that already has a webhook token keeps working: the flag is set to "on" here, and the
 * Integrations page shows it as deprecated until the owner moves the provider to the header and switches it off.
 * An explicit value already stored is never overwritten. Settings only: secrets are not read.
 */
return new class extends Migration
{
    private const array KEYS = ['phonet', 'ringostat', 'binotel'];

    public function up(): void
    {
        foreach ($this->integrationsWithToken() as $row) {
            $settings = $this->settings($row->settings);
            if (! array_key_exists(TelephonyWebhookFields::QUERY_TOKEN_FLAG, $settings)) {
                $settings[TelephonyWebhookFields::QUERY_TOKEN_FLAG] = TelephonyWebhookFields::ON;
                $this->save((int) $row->id, $settings);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->integrationsWithToken() as $row) {
            $settings = $this->settings($row->settings);
            if (array_key_exists(TelephonyWebhookFields::QUERY_TOKEN_FLAG, $settings)) {
                unset($settings[TelephonyWebhookFields::QUERY_TOKEN_FLAG]);
                $this->save((int) $row->id, $settings);
            }
        }
    }

    /** @return list<object{id: int|string, settings: string|null}> */
    private function integrationsWithToken(): array
    {
        /** @var list<object{id: int|string, settings: string|null}> */
        return DB::table('integrations')
            ->whereIn('key', self::KEYS)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('integration_secrets')
                ->whereColumn('integration_secrets.integration_id', 'integrations.id')
                ->where('integration_secrets.name', TelephonyWebhookFields::TOKEN))
            ->get(['id', 'settings'])
            ->all();
    }

    /** @return array<string, mixed> */
    private function settings(?string $json): array
    {
        $decoded = $json === null ? [] : json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<string, mixed>  $settings */
    private function save(int $id, array $settings): void
    {
        DB::table('integrations')->where('id', $id)->update([
            'settings' => json_encode((object) $settings, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
