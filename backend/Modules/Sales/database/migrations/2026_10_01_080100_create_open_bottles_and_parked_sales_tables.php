<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sell by tot. Opening a bottle takes it out of shop-floor stock at cost; every tot
     * (and any write-off of what is left) is an append-only pour against that bottle, so
     * managers can see how much each bottle actually brought in.
     *
     * Parked sales are carts put aside at a till (not sales: nothing is charged or posted).
     */
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->string('unit', 10)->default('bottle'); // bottle | tot
            $table->unsignedSmallInteger('tot_ml')->nullable();
        });

        Schema::create('open_bottles', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('volume_ml');
            $table->unsignedInteger('poured_ml')->default(0); // projection of open_bottle_pours
            $table->unsignedBigInteger('unit_cost_cents');    // bottle cost when opened
            $table->string('status', 20);                     // open | finished | written_off
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();

            $table->index(['branch_id', 'status']);
        });

        // One bottle of each item is poured from at a time per location.
        DB::statement("CREATE UNIQUE INDEX open_bottles_one_open ON open_bottles (location_id, variant_id) WHERE status = 'open'");
        DB::statement('ALTER TABLE open_bottles ADD CONSTRAINT open_bottles_not_overpoured CHECK (poured_ml <= volume_ml)');

        Schema::create('open_bottle_pours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('open_bottle_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);   // sale | write_off
            $table->unsignedInteger('ml');
            $table->unsignedBigInteger('sale_id')->nullable(); // FK below, checked at commit
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['open_bottle_id']);
        });

        // Tots are poured before the sale row is written (same transaction), so check at commit.
        DB::statement('ALTER TABLE open_bottle_pours ADD CONSTRAINT open_bottle_pours_sale_id_foreign FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED');

        Schema::create('parked_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('till_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('label', 60);
            $table->jsonb('lines');
            $table->unsignedBigInteger('total_cents');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['till_id']);
        });

        DB::unprepared(<<<'SQL'
            -- Bottles: only the pour projection and closing fields change.
            CREATE OR REPLACE FUNCTION open_bottles_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'open bottles cannot be deleted';
                END IF;
                IF (NEW.number, NEW.branch_id, NEW.location_id, NEW.variant_id, NEW.volume_ml, NEW.unit_cost_cents, NEW.opened_by, NEW.opened_at)
                   IS DISTINCT FROM
                   (OLD.number, OLD.branch_id, OLD.location_id, OLD.variant_id, OLD.volume_ml, OLD.unit_cost_cents, OLD.opened_by, OLD.opened_at)
                   OR NEW.poured_ml < OLD.poured_ml
                   OR OLD.status <> 'open' THEN
                    RAISE EXCEPTION 'open bottle history cannot be changed';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION open_bottle_pours_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'pours are append-only';
            END;
            $$ LANGUAGE plpgsql;

            -- Sale lines are guarded by sales_guard(); these two columns were added later.
            CREATE OR REPLACE FUNCTION sale_lines_unit_guard() RETURNS trigger AS $$
            BEGIN
                IF (NEW.unit, NEW.tot_ml) IS DISTINCT FROM (OLD.unit, OLD.tot_ml) THEN
                    RAISE EXCEPTION 'sale lines cannot be edited; record a return instead';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER sale_lines_unit_guard BEFORE UPDATE ON sale_lines FOR EACH ROW EXECUTE FUNCTION sale_lines_unit_guard();
            CREATE TRIGGER open_bottles_guard BEFORE UPDATE OR DELETE ON open_bottles FOR EACH ROW EXECUTE FUNCTION open_bottles_guard();
            CREATE TRIGGER open_bottle_pours_guard BEFORE UPDATE OR DELETE ON open_bottle_pours FOR EACH ROW EXECUTE FUNCTION open_bottle_pours_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sale_lines_unit_guard ON sale_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS sale_lines_unit_guard()');
        DB::unprepared('DROP TRIGGER IF EXISTS open_bottle_pours_guard ON open_bottle_pours');
        DB::unprepared('DROP TRIGGER IF EXISTS open_bottles_guard ON open_bottles');
        DB::unprepared('DROP FUNCTION IF EXISTS open_bottle_pours_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS open_bottles_guard()');
        Schema::dropIfExists('parked_sales');
        Schema::dropIfExists('open_bottle_pours');
        Schema::dropIfExists('open_bottles');
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropColumn(['unit', 'tot_ml']);
        });
    }
};
