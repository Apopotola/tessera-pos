<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documents that change stock only when approved (maker–checker):
     * adjustments (breakage, loss, opening stock…), transfers and stock counts.
     */
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);          // AdjustmentType enum
            $table->string('stage', 20)->nullable(); // where a loss happened: receiving|shelf|sale|transit|storage
            $table->string('status', 20);        // DocumentStatus enum
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->string('source_type', 30)->nullable(); // e.g. stock_transfer (transit shortfall)
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'status', 'created_at']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity'); // always positive; direction comes from the type
            $table->unsignedBigInteger('unit_cost_cents')->nullable(); // required for opening stock; snapshot at approval otherwise
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 20); // TransferStatus enum
            $table->text('note')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('dispatched_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();

            $table->index(['from_branch_id', 'status']);
            $table->index(['to_branch_id', 'status']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity_requested');
            $table->unsignedInteger('quantity_dispatched')->nullable();
            $table->unsignedInteger('quantity_received')->nullable();
            $table->unsignedBigInteger('unit_cost_cents')->nullable(); // source average cost at dispatch
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('status', 20); // CountStatus enum
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('counted_quantity')->nullable();
            $table->bigInteger('expected_quantity')->nullable(); // frozen at submit (blind until then)
            $table->bigInteger('variance')->nullable();
            $table->unsignedBigInteger('unit_cost_cents')->nullable();

            $table->unique(['stock_count_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
    }
};
