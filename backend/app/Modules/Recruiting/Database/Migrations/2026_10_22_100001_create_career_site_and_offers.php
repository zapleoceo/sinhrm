<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Career site: a vacancy can be published (slug + public description); applications from /jobs keep the message,
     * the consent timestamp, a hashed IP and the CV (base64, like documents_files). Offers: one per application,
     * generated from a Documents template of category "offer".
     */
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->boolean('published')->default(false);
            $table->string('slug', 150)->nullable()->unique();
            $table->text('public_description')->nullable();
        });

        Schema::create('career_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->text('message')->nullable();
            $table->timestamp('consent_at');
            $table->string('ip_hash', 64);
            $table->string('cv_filename')->nullable();
            $table->string('cv_mime', 128)->nullable();
            $table->unsignedInteger('cv_size')->nullable();
            $table->string('cv_sha256', 64)->nullable();
            $table->longText('cv_content')->nullable();
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained('applications')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->string('position', 255);
            $table->string('salary', 100);
            $table->date('start_date')->nullable();
            $table->text('conditions')->nullable();
            $table->text('content_md');
            $table->string('status', 16)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
        Schema::dropIfExists('career_submissions');
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn(['published', 'slug', 'public_description']);
        });
    }
};
