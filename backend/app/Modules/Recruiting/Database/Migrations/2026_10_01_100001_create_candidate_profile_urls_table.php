<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public profile pages of a candidate (LinkedIn, Work.ua, Djinni, DOU) imported by the browser extension.
 * The normalized URL is a dedupe key: one URL belongs to one candidate (unique), a candidate may have many.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_profile_urls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->string('site', 16);
            $table->string('url', 512)->unique();
            $table->timestamps();
            $table->index('candidate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_profile_urls');
    }
};
