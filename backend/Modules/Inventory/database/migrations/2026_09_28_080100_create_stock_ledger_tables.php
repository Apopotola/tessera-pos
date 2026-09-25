<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stock ledger. `stock_movements` is the only source of truth and is
     * append-only (trigger). `stock_balances` and `branch_variant_costs` are
     * projections maintained in the same transaction and can be rebuilt.
     * Quantities are whole variant units (bottles/cans); packs are converted before posting.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->bigInteger('quantity'); // signed: + in, − out
            $table->unsignedBigInteger('unit_cost_cents'); // cost per unit at posting time
            $table->string('movement_type', 30); // MovementType enum
            $table->string('document_type', 30);
            $table->unsignedBigInteger('document_id');
            $table->string('reference', 40);
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['variant_id', 'branch_id', 'occurred_at']);
            $table->index(['branch_id', 'occurred_at']);
            $table->index(['document_type', 'document_id']);
            $table->index(['movement_type', 'occurred_at']);
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_nonzero CHECK (quantity <> 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'stock_movements is append-only (% blocked); post a reversing movement instead', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER stock_movements_no_update_delete
                BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_block_mutation();
        SQL);

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->bigInteger('quantity')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['location_id', 'variant_id']);
            $table->index(['branch_id', 'variant_id']);
        });

        // Weighted average cost per branch (transit stock excluded).
        Schema::create('branch_variant_costs', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedBigInteger('avg_cost_cents')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['branch_id', 'variant_id']);
        });

        Schema::create('reorder_levels', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('reorder_level');
            $table->unsignedInteger('reorder_quantity');
            $table->timestampsTz();

            $table->primary(['branch_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reorder_levels');
        Schema::dropIfExists('branch_variant_costs');
        Schema::dropIfExists('stock_balances');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update_delete ON stock_movements');
        Schema::dropIfExists('stock_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS stock_movements_block_mutation()');
    }
};
