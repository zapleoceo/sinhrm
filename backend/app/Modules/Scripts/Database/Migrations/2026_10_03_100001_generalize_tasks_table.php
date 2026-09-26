<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified tasks: besides recruiter follow-ups, tasks may belong to an employee (workflows, documents) and carry an
 * in-app link. rule_key stays the idempotency key: "wf:<run step id>", "doc:<document id>" are unique per employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->after('application_id')->constrained('employees')->cascadeOnDelete();
            // Relative in-app path (e.g. "/people/12"), never an external URL with a token.
            $table->string('link', 255)->nullable()->after('title');
            $table->unique(['employee_id', 'rule_key']);
            $table->index(['employee_id', 'done_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique(['employee_id', 'rule_key']);
            $table->dropIndex(['employee_id', 'done_at']);
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('link');
        });
    }
};
