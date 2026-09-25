<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M-PESA. A confirmation is Safaricom's word that money arrived (STK callback or C2B
     * confirmation); it is the only thing that can make an M-PESA tender "confirmed".
     * Confirmations are append-only apart from being allocated to a tender, once.
     */
    public function up(): void
    {
        Schema::create('mpesa_confirmations', function (Blueprint $table) {
            $table->id();
            $table->string('source', 10);                     // stk | c2b
            $table->string('receipt', 20)->unique();          // M-PESA transaction id, e.g. SIP4XK9ABC
            $table->unsignedBigInteger('amount_cents');
            $table->string('phone_masked', 20)->nullable();   // 0712***678; full number stays in the request log
            $table->string('payer_name', 120)->nullable();
            $table->string('shortcode', 20)->nullable();
            $table->string('bill_reference', 40)->nullable();
            $table->timestampTz('transacted_at');
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete(); // known for STK
            $table->jsonb('payload');
            $table->foreignId('tender_id')->nullable()->unique()->constrained('sale_tenders')->restrictOnDelete();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('allocated_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tender_id', 'transacted_at']);
        });

        // One STK Push per row. Phone numbers are encrypted at rest (access-restricted payment log).
        Schema::create('mpesa_stk_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('till_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('phone');                            // encrypted
            $table->string('phone_masked', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('merchant_request_id', 60)->nullable();
            $table->string('checkout_request_id', 60)->nullable()->unique();
            $table->string('status', 20);                     // pending | paid | failed
            $table->string('result_code', 10)->nullable();
            $table->string('result_description', 255)->nullable();
            $table->foreignId('confirmation_id')->nullable()->constrained('mpesa_confirmations')->restrictOnDelete();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampsTz();

            $table->index(['till_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mpesa_confirmations_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'M-PESA confirmations cannot be deleted';
                END IF;
                IF (NEW.source, NEW.receipt, NEW.amount_cents, NEW.transacted_at, NEW.payload::text)
                   IS DISTINCT FROM (OLD.source, OLD.receipt, OLD.amount_cents, OLD.transacted_at, OLD.payload::text)
                   OR OLD.tender_id IS NOT NULL THEN
                    RAISE EXCEPTION 'M-PESA confirmations are immutable once recorded and allocated';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER mpesa_confirmations_guard BEFORE UPDATE OR DELETE ON mpesa_confirmations
                FOR EACH ROW EXECUTE FUNCTION mpesa_confirmations_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS mpesa_confirmations_guard ON mpesa_confirmations');
        DB::unprepared('DROP FUNCTION IF EXISTS mpesa_confirmations_guard()');
        Schema::dropIfExists('mpesa_stk_requests');
        Schema::dropIfExists('mpesa_confirmations');
    }
};
