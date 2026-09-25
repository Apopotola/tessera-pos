<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cashier shifts. One open shift per till and one per cashier at a time
     * (partial unique indexes). Cash sales and refunds feed expected cash once
     * the Sales module records them; for now expected = opening float.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('till_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('opening_float_cents');
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedBigInteger('expected_cash_cents')->nullable();
            $table->unsignedBigInteger('counted_cash_cents')->nullable();
            $table->bigInteger('variance_cents')->nullable();
            $table->text('close_note')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'opened_at']);
        });

        DB::statement('CREATE UNIQUE INDEX shifts_one_open_per_till ON shifts (till_id) WHERE closed_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX shifts_one_open_per_user ON shifts (user_id) WHERE closed_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
