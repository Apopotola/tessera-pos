<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer credit accounts (Phase 2): a credit limit and payment terms per approved
     * customer. What they owe comes from sales paid "on account" (sale_tenders method credit),
     * returns credited back to the account, and payments received — never a stored balance.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // null = no credit account; 0 = account on hold (every credit sale needs a manager).
            $table->unsignedBigInteger('credit_limit_cents')->nullable()->after('is_wholesale');
            $table->unsignedSmallInteger('credit_terms_days')->default(30)->after('credit_limit_cents');
        });

        // Money received against a customer's account. Append-only; a mistake is corrected
        // by a reversal row (negative amount) pointing at the payment it cancels.
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('method', 20); // cash | mpesa | bank | card | cheque
            $table->string('reference', 60)->nullable();
            $table->timestampTz('received_at');
            $table->text('note')->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('customer_payments')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['customer_id', 'received_at']);
        });

        DB::statement('ALTER TABLE customer_payments ADD CONSTRAINT customer_payments_sign CHECK ((reverses_id IS NULL AND amount_cents > 0) OR (reverses_id IS NOT NULL AND amount_cents < 0))');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION customer_payments_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'customer payments are append-only; record a reversal instead';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER customer_payments_guard BEFORE UPDATE OR DELETE ON customer_payments FOR EACH ROW EXECUTE FUNCTION customer_payments_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS customer_payments_guard ON customer_payments');
        DB::unprepared('DROP FUNCTION IF EXISTS customer_payments_guard()');
        Schema::dropIfExists('customer_payments');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['credit_limit_cents', 'credit_terms_days']);
        });
    }
};
