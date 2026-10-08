<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional "who takes over the work" during the absence (PROD-13). Nullable: existing rows stay valid;
        // deleting the colleague's card only clears the link. Explicit index on the foreign key column.
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->foreignId('handover_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->index('handover_to_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropForeign(['handover_to_employee_id']);
            $table->dropIndex(['handover_to_employee_id']);
            $table->dropColumn('handover_to_employee_id');
        });
    }
};
