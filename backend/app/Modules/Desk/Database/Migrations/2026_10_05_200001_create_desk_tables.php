<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Case categories with their SLA (hours from opening; null = no target) and a default assignee.
        Schema::create('desk_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->unsignedSmallInteger('first_response_hours')->nullable();
            $table->unsignedSmallInteger('resolve_hours')->nullable();
            $table->foreignId('default_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('desk_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('desk_categories');
            $table->string('subject', 200);
            $table->text('body');
            // new | in_progress | waiting | resolved | closed
            $table->string('status', 16)->default('new');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('employee_id');
        });

        // Thread: public replies (the employee sees them) and internal notes (HR only); an optional knowledge article.
        Schema::create('desk_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('desk_cases')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->unsignedBigInteger('article_id')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'id']);
        });

        // Small files (<= 2 MB, the Documents whitelist detected from the bytes) kept in the database as base64.
        Schema::create('desk_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('desk_cases')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename', 255);
            $table->string('mime', 128);
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->longText('content');
            $table->timestamps();
            $table->index('case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_attachments');
        Schema::dropIfExists('desk_comments');
        Schema::dropIfExists('desk_cases');
        Schema::dropIfExists('desk_categories');
    }
};
