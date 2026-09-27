<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Мій профіль" → e-mail me about approvals and decisions (Core\Contracts\UserNotifier), on by default.
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('approval_emails')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('approval_emails');
        });
    }
};
