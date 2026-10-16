<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M-PESA codes typed at an offline till. One code can be used once per till (unique index),
     * whether or not Safaricom's confirmation had arrived when the sale was synced. Append-only.
     */
    public function up(): void
    {
        Schema::create('offline_mpesa_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('till_id')->constrained()->restrictOnDelete();
            $table->string('receipt', 20);
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('tender_id')->constrained('sale_tenders')->restrictOnDelete();
            // Why it was not confirmed on sync (null when it was).
            $table->string('problem', 200)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['till_id', 'receipt']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION offline_mpesa_codes_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'offline_mpesa_codes is append-only (% blocked)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER offline_mpesa_codes_no_update_delete
                BEFORE UPDATE OR DELETE ON offline_mpesa_codes
                FOR EACH ROW EXECUTE FUNCTION offline_mpesa_codes_block_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_mpesa_codes');
        DB::unprepared('DROP FUNCTION IF EXISTS offline_mpesa_codes_block_mutation()');
    }
};
