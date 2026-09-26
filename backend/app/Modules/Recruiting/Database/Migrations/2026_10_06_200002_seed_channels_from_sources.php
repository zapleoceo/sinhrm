<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data migration source → channel (tz3). Every "where from" value of the old candidates.source enum becomes a channel
 * with the same code; "manual", "import" and "inbox" describe HOW the candidate was added, not where from, so they
 * become added_via (channel stays null). A few common Ukrainian channels are added; UTM rules for the obvious sources.
 * Existing candidates: channel_id by code = source; added_via: manual/inbox → manual, import → import,
 * a profile URL from the browser extension → extension; the rest stays null (unknown).
 */
return new class extends Migration
{
    /** code => [name, type] */
    public const array CHANNELS = [
        'work_ua' => ['Work.ua', 'job_board'],
        'robota_ua' => ['Robota.ua', 'job_board'],
        'djinni' => ['Djinni', 'job_board'],
        'dou' => ['DOU', 'job_board'],
        'grc_ua' => ['GRC.ua', 'job_board'],
        'linkedin' => ['LinkedIn', 'social'],
        'telegram' => ['Telegram', 'social'],
        'instagram' => ['Instagram', 'social'],
        'meta_ads' => ['Meta Ads (Facebook/Instagram)', 'ads'],
        'google_ads' => ['Google Ads', 'ads'],
        'site' => ['Career site', 'site'],
        'referral' => ['Employee referral', 'referral'],
        'job_fair' => ['Job fair', 'event'],
        'agency' => ['Recruiting agency', 'agency'],
        'other' => ['Other', 'other'],
    ];

    /** [utm_source, utm_medium, channel code] */
    private const array RULES = [
        ['work.ua', null, 'work_ua'],
        ['workua', null, 'work_ua'],
        ['robota.ua', null, 'robota_ua'],
        ['djinni', null, 'djinni'],
        ['dou', null, 'dou'],
        ['linkedin', null, 'linkedin'],
        ['telegram', null, 'telegram'],
        ['instagram', null, 'instagram'],
        ['facebook', 'paid', 'meta_ads'],
        ['instagram', 'paid', 'meta_ads'],
        ['google', 'cpc', 'google_ads'],
    ];

    public function up(): void
    {
        $now = Carbon::now();
        foreach (self::CHANNELS as $code => [$name, $type]) {
            if (! DB::table('acquisition_channels')->where('code', $code)->exists()) {
                DB::table('acquisition_channels')->insert(['code' => $code, 'name' => $name, 'type' => $type, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $ids = DB::table('acquisition_channels')->pluck('id', 'code');
        foreach (self::RULES as [$source, $medium, $code]) {
            $exists = DB::table('channel_utm_rules')->where('channel_id', $ids[$code])->where('utm_source', $source)
                ->when($medium === null, fn (Builder $q) => $q->whereNull('utm_medium'), fn (Builder $q) => $q->where('utm_medium', $medium))
                ->exists();
            if ($exists) {
                continue; // re-runnable
            }
            DB::table('channel_utm_rules')->insert([
                'channel_id' => $ids[$code], 'utm_source' => $source, 'utm_medium' => $medium, 'utm_campaign' => null,
                'priority' => 100, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Portable UPDATE … = (subquery): works on Postgres and SQLite.
        DB::statement('UPDATE candidates SET channel_id = (SELECT c.id FROM acquisition_channels c WHERE c.code = candidates.source) WHERE channel_id IS NULL');
        DB::table('candidates')->whereNull('added_via')->whereIn('source', ['manual', 'inbox'])->update(['added_via' => 'manual']);
        DB::table('candidates')->whereNull('added_via')->where('source', 'import')->update(['added_via' => 'import']);
        DB::table('candidates')->whereNull('added_via')
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('candidate_profile_urls as p')->whereColumn('p.candidate_id', 'candidates.id'))
            ->update(['added_via' => 'extension']);
    }

    public function down(): void
    {
        DB::table('candidates')->update(['channel_id' => null, 'added_via' => null]);
        DB::table('channel_utm_rules')->delete();
        DB::table('acquisition_channel_costs')->delete();
        DB::table('acquisition_channels')->delete();
    }
};
