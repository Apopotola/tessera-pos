<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dated price list. Rows are never edited or deleted: a new price is a new row.
     * The only permitted update is the maker–checker review of a pending row
     * (status, reviewer, review note and — on approval — effective_from).
     */
    public function up(): void
    {
        Schema::create('variant_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete(); // null = all branches
            $table->string('tier', 20); // PriceTier enum
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('min_price_cents')->nullable();
            $table->timestampTz('effective_from');
            $table->string('status', 20); // PriceStatus enum
            $table->text('reason')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['variant_id', 'tier', 'status', 'effective_from']);
            $table->index(['status', 'created_at']);
        });

        DB::statement('ALTER TABLE variant_prices ADD CONSTRAINT variant_prices_price_positive CHECK (price_cents > 0)');
        DB::statement('ALTER TABLE variant_prices ADD CONSTRAINT variant_prices_min_not_above_price CHECK (min_price_cents IS NULL OR min_price_cents <= price_cents)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION variant_prices_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'variant_prices is append-only (DELETE blocked)';
                END IF;

                IF OLD.status <> 'pending'
                   OR NEW.variant_id IS DISTINCT FROM OLD.variant_id
                   OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
                   OR NEW.tier IS DISTINCT FROM OLD.tier
                   OR NEW.price_cents IS DISTINCT FROM OLD.price_cents
                   OR NEW.min_price_cents IS DISTINCT FROM OLD.min_price_cents
                   OR NEW.requested_by IS DISTINCT FROM OLD.requested_by
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'variant_prices rows are immutable except for reviewing a pending request';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER variant_prices_guard
                BEFORE UPDATE OR DELETE ON variant_prices
                FOR EACH ROW EXECUTE FUNCTION variant_prices_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS variant_prices_guard ON variant_prices');
        Schema::dropIfExists('variant_prices');
        DB::unprepared('DROP FUNCTION IF EXISTS variant_prices_guard()');
    }
};
