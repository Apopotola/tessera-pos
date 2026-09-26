<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promotions (Phase 2 price rules, e.g. "buy 6 wine, 10% off"): time-boxed and approved by
     * the owner. The till and the API apply the best one per sale line; the line keeps which
     * promotion and how much it took off.
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('status', 20);                        // pending | active | rejected | ended
            $table->string('discount_type', 10);                 // percent | amount
            $table->unsignedInteger('discount_value');           // percent: basis points (1000 = 10%); amount: cents off each unit
            $table->unsignedInteger('min_quantity')->default(1); // across all matching items in the sale
            $table->string('unit', 10)->default('any');          // bottle | tot | any
            $table->date('starts_on');
            $table->date('ends_on');
            $table->jsonb('weekdays')->nullable();               // ISO 1 (Mon) … 7 (Sun); null = every day
            $table->string('time_from', 5)->nullable();          // happy hour, e.g. 17:00–19:00
            $table->string('time_to', 5)->nullable();
            $table->jsonb('branch_ids')->nullable();             // null = every branch
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'starts_on', 'ends_on']);
        });

        // What it applies to; none = every item.
        Schema::create('promotion_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 10); // category | brand | variant
            $table->unsignedBigInteger('target_id');

            $table->index(['promotion_id']);
        });

        Schema::table('sale_lines', function (Blueprint $table) {
            $table->foreignId('promotion_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('promotion_discount_cents')->default(0); // on top of discount_cents (the cashier's)
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn('promotion_discount_cents');
        });
        Schema::dropIfExists('promotion_targets');
        Schema::dropIfExists('promotions');
    }
};
