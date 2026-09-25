<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Till PINs (hashed) and phone-number sign-in. Phone numbers are stored in
     * canonical +254 form (App\Support\PhoneNumber) and must be unique.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_hash')->nullable()->after('password');
            $table->timestampTz('pin_set_at')->nullable()->after('pin_hash');
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['pin_hash', 'pin_set_at']);
        });
    }
};
