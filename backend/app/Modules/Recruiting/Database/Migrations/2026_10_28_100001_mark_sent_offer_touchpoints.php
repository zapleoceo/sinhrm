<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data migration (security audit 2026-10): offer e-mails sent before meta.kind = "offer" existed (OfferService::send
 * since #183) carry the salary in the touch body but were not marked, so the timeline showed them to everyone with
 * access to the application. This marks them: an outgoing e-mail touch of an application whose offer was sent
 * (offers.sent_at set) and whose subject — meta.subject, or the first body line for old rows — starts with
 * "Оффер: " (the subject OfferService::send gives it). Read and written in chunks; idempotent (rows already marked
 * are skipped), MySQL 8.4 compatible (JSON decoded and encoded in PHP, no JSON SQL functions).
 *
 * Rollback: nothing to undo — hiding the offer text is the intended state, a down() that un-hides salaries would
 * reopen the leak.
 */
return new class extends Migration
{
    private const string SUBJECT_PREFIX = 'Оффер: ';

    private const int CHUNK = 200;

    public function up(): void
    {
        DB::table('offers')->whereNotNull('sent_at')->select(['id', 'application_id'])
            ->chunkById(self::CHUNK, function (Collection $offers): void {
                $applicationIds = $offers->pluck('application_id')->all();
                DB::table('touchpoints')
                    ->whereIn('application_id', $applicationIds)
                    ->where('channel', 'email')
                    ->where('direction', 'out')
                    ->select(['id', 'body', 'meta'])
                    ->orderBy('id')
                    ->each(function (object $touch): void {
                        $meta = is_string($touch->meta) ? json_decode($touch->meta, true) : null;
                        $meta = is_array($meta) ? $meta : [];
                        if (($meta['kind'] ?? null) === 'offer' || ! $this->isOfferSubject($meta, $touch->body)) {
                            return;
                        }
                        DB::table('touchpoints')->where('id', $touch->id)
                            ->update(['meta' => json_encode([...$meta, 'kind' => 'offer'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                    }, self::CHUNK);
            });
    }

    public function down(): void
    {
        // Intentionally empty: see the class docblock.
    }

    /** @param  array<string, mixed>  $meta */
    private function isOfferSubject(array $meta, mixed $body): bool
    {
        $subject = is_string($meta['subject'] ?? null) ? $meta['subject'] : strtok(is_string($body) ? $body : '', "\n");

        return is_string($subject) && str_starts_with($subject, self::SUBJECT_PREFIX);
    }
};
