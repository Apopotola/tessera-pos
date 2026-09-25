<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A till is a registered selling device at a branch. The device proves its
     * identity with a random token; only the SHA-256 hash is stored.
     */
    public function up(): void
    {
        Schema::create('tills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 40);
            $table->string('description', 80)->nullable();
            $table->unsignedBigInteger('default_float_cents')->default(0);
            $table->string('device_token_hash', 64)->nullable()->unique();
            $table->timestampTz('paired_at')->nullable();
            $table->foreignId('paired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tills');
    }
};
