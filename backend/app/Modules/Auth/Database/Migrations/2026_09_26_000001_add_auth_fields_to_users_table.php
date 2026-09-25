<?php

declare(strict_types=1);

use App\Modules\Auth\Enums\AppLocale;
use App\Modules\Auth\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Users sign in with Google only; invited users have no password.
            $table->string('password')->nullable()->change();
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar_url', 2048)->nullable();
            $table->string('status', 16)->default(UserStatus::Active->value)->index();
            $table->string('locale', 5)->default(AppLocale::Uk->value);
            $table->timestamp('last_login_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['google_id', 'avatar_url', 'status', 'locale', 'last_login_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
