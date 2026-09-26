<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supplier payments and balances (Phase 2). A supplier's balance = matched invoices
     * − payments − credit notes for returned stock. Invoices and payments only affect
     * the supplier balance, never stock.
     */
    public function up(): void
    {
        // Append-only; corrected by a reversal row (negative amount).
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('method', 20); // bank | mpesa | cash | cheque
            $table->string('reference', 60)->nullable();
            $table->date('paid_on');
            $table->text('note')->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('supplier_payments')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['supplier_id', 'paid_on']);
        });

        DB::statement('ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_sign CHECK ((reverses_id IS NULL AND amount_cents > 0) OR (reverses_id IS NOT NULL AND amount_cents < 0))');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION supplier_payments_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'supplier payments are append-only; record a reversal instead';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_payments_guard BEFORE UPDATE OR DELETE ON supplier_payments FOR EACH ROW EXECUTE FUNCTION supplier_payments_guard();
        SQL);

        // The supplier's credit note for returned stock: its value lowers what we owe.
        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_note_cents')->nullable()->after('credit_note_ref');
            $table->date('credit_note_date')->nullable()->after('credit_note_cents');
            $table->foreignId('credit_noted_by')->nullable()->after('credit_note_date')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_noted_by');
            $table->dropColumn(['credit_note_cents', 'credit_note_date']);
        });
        DB::unprepared('DROP TRIGGER IF EXISTS supplier_payments_guard ON supplier_payments');
        DB::unprepared('DROP FUNCTION IF EXISTS supplier_payments_guard()');
        Schema::dropIfExists('supplier_payments');
    }
};
