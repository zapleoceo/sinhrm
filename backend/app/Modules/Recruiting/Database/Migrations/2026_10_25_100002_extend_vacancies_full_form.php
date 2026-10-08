<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Full vacancy form: category (Directory dictionary), work conditions (codes from VacancyOptions), salary
     * (internal; public only when salary_visible), languages, Markdown sections, manual references to ads on external
     * job sites, and saved form templates. All new columns are nullable or defaulted (existing rows stay valid).
     */
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->constrained('vacancy_categories')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('country', 2)->nullable();
            $table->string('employment_type', 24)->nullable();
            $table->string('work_format', 16)->nullable();
            $table->string('experience_level', 24)->nullable();
            $table->string('education_level', 24)->nullable();
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->string('salary_currency', 3)->default('UAH');
            $table->boolean('salary_visible')->default(false);
            // [{lang: "en", level: "B2"}]
            $table->json('languages')->nullable();
            $table->text('requirements')->nullable();
            $table->text('responsibilities')->nullable();
            $table->text('additional_info')->nullable();
            // [{site: "work_ua", url: "https://…", date: "2026-10-25"}] — manual references, no integration.
            $table->json('external_postings')->nullable();
        });

        Schema::create('vacancy_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            // Form values without the title-specific state (status, publication, slug).
            $table->json('data');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_templates');
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('city_id');
            $table->dropColumn([
                'country', 'employment_type', 'work_format', 'experience_level', 'education_level', 'salary_min',
                'salary_max', 'salary_currency', 'salary_visible', 'languages', 'requirements', 'responsibilities',
                'additional_info', 'external_postings',
            ]);
        });
    }
};
