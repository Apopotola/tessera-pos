<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Till sales, their lines, tenders and returns. Prices are VAT-inclusive (retail).
     * Completed sales are immutable: triggers allow only the status fields a return or
     * eTIMS submission changes; everything else is corrected with a return document.
     */
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_id')->unique(); // idempotency key from the till
            $table->string('number', 40)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('till_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // cashier
            $table->string('customer_pin', 20)->nullable(); // buyer KRA PIN for B2B invoices
            $table->unsignedBigInteger('subtotal_cents');  // before discounts, incl. VAT
            $table->unsignedBigInteger('discount_cents');
            $table->unsignedBigInteger('total_cents');     // paid, incl. VAT
            $table->unsignedBigInteger('vat_cents');
            $table->unsignedBigInteger('cost_cents');      // cost of goods sold
            $table->string('status', 20);                  // completed | partially_returned | returned
            $table->string('etims_status', 20)->default('pending');
            $table->timestampTz('completed_at');
            $table->timestampsTz();

            $table->index(['branch_id', 'completed_at']);
            $table->index(['shift_id']);
            $table->index(['etims_status']);
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('list_price_cents');  // price-list price per unit
            $table->unsignedBigInteger('unit_price_cents');  // price charged per unit (≠ list when overridden)
            $table->unsignedBigInteger('discount_cents');    // whole-line discount
            $table->unsignedBigInteger('line_total_cents');  // qty × unit price − discount
            $table->unsignedBigInteger('vat_cents');
            $table->unsignedInteger('tax_rate_bp');
            $table->unsignedBigInteger('unit_cost_cents');
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete(); // override / big discount
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('till_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('vat_cents');
            $table->string('etims_status', 20)->default('pending'); // credit note
            $table->timestampsTz();
        });

        Schema::create('sale_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->boolean('restocked'); // sealed → shop floor; opened/broken → quarantine
            $table->unsignedBigInteger('amount_cents');
        });

        // Money in (+) and refunds out (−) per shift. Cash tenders drive the cash-up.
        Schema::create('sale_tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sale_return_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('method', 20);                        // cash | mpesa | card
            $table->bigInteger('amount_cents');                  // applied to the sale; negative for refunds
            $table->unsignedBigInteger('tendered_cents')->nullable(); // cash handed over
            $table->unsignedBigInteger('change_cents')->nullable();
            $table->string('reference', 60)->nullable();         // M-PESA code / card approval code
            $table->string('card_last4', 4)->nullable();
            $table->string('status', 20);                        // confirmed | unverified
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['shift_id', 'method']);
        });

        DB::unprepared(<<<'SQL'
            -- One IF block per table: PL/pgSQL resolves every NEW.field in a condition,
            -- so a column that only one table has must sit inside that table's block.
            CREATE OR REPLACE FUNCTION sales_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '% rows cannot be deleted; record a return instead', TG_TABLE_NAME;
                END IF;

                IF TG_TABLE_NAME = 'sales' THEN
                    IF (NEW.client_id, NEW.number, NEW.branch_id, NEW.till_id, NEW.shift_id, NEW.user_id,
                        NEW.subtotal_cents, NEW.discount_cents, NEW.total_cents, NEW.vat_cents, NEW.cost_cents, NEW.completed_at)
                       IS DISTINCT FROM
                       (OLD.client_id, OLD.number, OLD.branch_id, OLD.till_id, OLD.shift_id, OLD.user_id,
                        OLD.subtotal_cents, OLD.discount_cents, OLD.total_cents, OLD.vat_cents, OLD.cost_cents, OLD.completed_at) THEN
                        RAISE EXCEPTION 'completed sales cannot be edited; record a return instead';
                    END IF;
                ELSIF TG_TABLE_NAME = 'sale_lines' THEN
                    IF (NEW.sale_id, NEW.variant_id, NEW.quantity, NEW.unit_price_cents, NEW.discount_cents, NEW.line_total_cents, NEW.unit_cost_cents)
                       IS DISTINCT FROM
                       (OLD.sale_id, OLD.variant_id, OLD.quantity, OLD.unit_price_cents, OLD.discount_cents, OLD.line_total_cents, OLD.unit_cost_cents) THEN
                        RAISE EXCEPTION 'sale lines cannot be edited; record a return instead';
                    END IF;
                ELSIF TG_TABLE_NAME = 'sale_returns' THEN
                    -- Only the eTIMS credit-note status may change.
                    IF (NEW.number, NEW.sale_id, NEW.total_cents, NEW.vat_cents) IS DISTINCT FROM (OLD.number, OLD.sale_id, OLD.total_cents, OLD.vat_cents) THEN
                        RAISE EXCEPTION 'sale returns are immutable';
                    END IF;
                ELSE
                    RAISE EXCEPTION '% rows are immutable', TG_TABLE_NAME;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER sales_guard BEFORE UPDATE OR DELETE ON sales FOR EACH ROW EXECUTE FUNCTION sales_guard();
            CREATE TRIGGER sale_lines_guard BEFORE UPDATE OR DELETE ON sale_lines FOR EACH ROW EXECUTE FUNCTION sales_guard();
            CREATE TRIGGER sale_tenders_guard BEFORE UPDATE OR DELETE ON sale_tenders FOR EACH ROW EXECUTE FUNCTION sales_guard();
            CREATE TRIGGER sale_returns_guard BEFORE UPDATE OR DELETE ON sale_returns FOR EACH ROW EXECUTE FUNCTION sales_guard();
            CREATE TRIGGER sale_return_lines_guard BEFORE UPDATE OR DELETE ON sale_return_lines FOR EACH ROW EXECUTE FUNCTION sales_guard();
        SQL);
    }

    public function down(): void
    {
        foreach (['sale_return_lines', 'sale_returns', 'sale_tenders', 'sale_lines', 'sales'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_guard ON {$table}");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS sales_guard ON sales');
        Schema::dropIfExists('sale_tenders');
        Schema::dropIfExists('sale_return_lines');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
        DB::unprepared('DROP FUNCTION IF EXISTS sales_guard()');
    }
};
