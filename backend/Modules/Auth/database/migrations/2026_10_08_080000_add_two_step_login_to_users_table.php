<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two-step login (TOTP authenticator app) and password age.
     * The secret is stored encrypted; recovery codes are stored hashed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->after('pin_set_at');
            $table->timestampTz('mfa_enabled_at')->nullable()->after('mfa_secret');
            $table->jsonb('mfa_recovery_codes')->nullable()->after('mfa_enabled_at');
            // Last 30-second step accepted, so a code cannot be used twice.
            $table->bigInteger('mfa_last_step')->nullable()->after('mfa_recovery_codes');
            $table->timestampTz('password_changed_at')->nullable()->after('password');
        });

        DB::table('users')->update(['password_changed_at' => DB::raw('COALESCE(updated_at, now())')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_enabled_at', 'mfa_recovery_codes', 'mfa_last_step', 'password_changed_at']);
        });
    }
};
