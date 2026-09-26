<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acquisition channels (tz3): a managed dictionary replacing the fixed candidates.source enum for analytics,
 * UTM mapping rules (utm_source / utm_medium / utm_campaign → channel), channel costs per period, and on the
 * candidate: channel_id (where they came from) and added_via (how the record got into SinHRM) — two separate facts.
 * candidates.source stays for backward compatibility of the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_channels', function (Blueprint $table): void {
            $table->id();
            // Technical name used by links / imports ("work_ua", "meta_ads"); unique, lowercase.
            $table->string('code', 50)->unique();
            $table->string('name', 120);
            // job_board | ads | referral | social | site | event | agency | other
            $table->string('type', 16)->default('other');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Spend on a channel for a period (a job-board contract, an ad campaign); reports prorate it by days.
        Schema::create('acquisition_channel_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained('acquisition_channels')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('UAH');
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'period_start']);
        });

        // A rule matches when every non-null field equals the candidate's UTM value (case-insensitive, stored lowercase).
        Schema::create('channel_utm_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained('acquisition_channels')->cascadeOnDelete();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamps();
            $table->index('channel_id');
        });

        Schema::table('candidates', function (Blueprint $table): void {
            $table->foreignId('channel_id')->nullable()->constrained('acquisition_channels')->nullOnDelete();
            // manual | import | mail | extension | webhook | sheets (null = unknown, candidates created before tz3)
            $table->string('added_via', 16)->nullable();
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->dropIndex(['channel_id']);
            $table->dropConstrainedForeignId('channel_id');
            $table->dropColumn('added_via');
        });
        Schema::dropIfExists('channel_utm_rules');
        Schema::dropIfExists('acquisition_channel_costs');
        Schema::dropIfExists('acquisition_channels');
    }
};
