<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passwordless customers (milestone 4): no password and, until they
     * fill in their profile, no name. Soft deletes for the deletion grace
     * period, and a session version for "sign out everywhere".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->unsignedInteger('session_version')->default(0)->after('remember_token');
            $table->softDeletes()->index();
            $table->dateTime('anonymised_at')->nullable()->after('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn(['session_version', 'anonymised_at']);
        });
    }
};
