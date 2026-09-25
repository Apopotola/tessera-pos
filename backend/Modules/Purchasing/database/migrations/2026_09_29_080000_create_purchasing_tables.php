<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suppliers → purchase orders → goods received (posts stock) → supplier invoices
     * (three-way match) and returns to supplier. Costs are per variant unit, excluding VAT;
     * each line snapshots the VAT rate in force (REQUIRES VALIDATION with the accountant).
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('kra_pin', 20)->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->text('payment_details')->nullable(); // bank / M-PESA paybill — company data
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX suppliers_name_lower_unique ON suppliers (lower(name))');

        // What a supplier sells us: their code for it and the last price paid.
        Schema::create('supplier_items', function (Blueprint $table) {
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('supplier_code', 60)->nullable();
            $table->unsignedBigInteger('last_cost_cents')->nullable();
            $table->timestampTz('last_purchased_at')->nullable();

            $table->primary(['supplier_id', 'variant_id']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete(); // deliver to
            $table->string('status', 20); // PurchaseOrderStatus enum
            $table->date('expected_date')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['supplier_id', 'created_at']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->unsignedInteger('quantity_damaged')->default(0);
            $table->unsignedBigInteger('unit_cost_cents'); // excluding VAT
            $table->unsignedInteger('tax_rate_bp');

            $table->unique(['purchase_order_id', 'variant_id']);
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number', 60);
            $table->date('invoice_date');
            $table->date('due_date');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('vat_cents');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('expected_total_cents'); // value of the linked GRNs incl. VAT
            $table->bigInteger('variance_cents');               // total − expected
            $table->string('match_status', 20);                 // matched | variance
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['supplier_id', 'invoice_number']);
        });

        Schema::create('goods_received_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('delivery_note_ref', 60)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('supplier_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();

            $table->index(['supplier_id', 'supplier_invoice_id']);
        });

        Schema::create('goods_received_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_received_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity_received'); // good units, posted to stock
            $table->unsignedInteger('quantity_damaged');  // damaged on arrival, not taken into stock
            $table->unsignedBigInteger('unit_cost_cents');
            $table->unsignedInteger('tax_rate_bp');
            $table->string('batch_number', 60)->nullable();
            $table->date('expiry_date')->nullable();
        });

        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('status', 20); // pending | approved | rejected
            $table->text('reason');
            $table->string('credit_note_ref', 60)->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('supplier_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_cost_cents')->nullable(); // snapshot at approval
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_return_lines');
        Schema::dropIfExists('supplier_returns');
        Schema::dropIfExists('goods_received_lines');
        Schema::dropIfExists('goods_received_notes');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('supplier_items');
        Schema::dropIfExists('suppliers');
    }
};
