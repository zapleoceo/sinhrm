<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Templates: plain text / Markdown with {Variables}; raw HTML is never rendered (escaped on output).
        Schema::create('document_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('category', 64)->nullable();
            $table->text('body');
            $table->boolean('archived')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->string('title');
            $table->string('category', 64)->nullable();
            // draft | sent | signed | rejected | archived
            $table->string('status', 16)->default('draft');
            $table->text('content_md')->nullable();
            // Storage reference of an attached file ("db:<documents_files.id>" for the database storage).
            $table->string('file_path', 255)->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        // Small files (<= 2 MB, pdf/png/jpg/docx) kept in the database as base64 until an object storage is chosen.
        Schema::create('documents_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('documents')->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('mime', 128);
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->longText('content');
            $table->timestamps();
        });

        // Acknowledgement ("Ознайомлений") and, later, qualified e-signatures. IP and user agent are stored as keyed
        // hashes only (proof of the same client without keeping the raw values).
        Schema::create('signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('signer_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('signer_user_id')->nullable()->constrained('users')->nullOnDelete();
            // manual_ack | kep_pending
            $table->string('method', 16);
            $table->timestamp('signed_at');
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'signer_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
        Schema::dropIfExists('documents_files');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_templates');
    }
};
