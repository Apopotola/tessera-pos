<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * eTIMS outbox. A row is written in the same transaction as its sale or return, so no
     * document can exist without its eTIMS job. KRA field names REQUIRE VALIDATION against
     * the VSCU/OSCU v2.0 spec; request/response bodies are kept whole for that reason.
     */
    public function up(): void
    {
        Schema::create('etims_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 20);          // sale | credit_note
            $table->unsignedBigInteger('document_id');
            $table->string('document_number', 40);        // our invoice number, same on every retry
            $table->string('original_document_number', 40)->nullable(); // credit note → original sale
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);                 // pending | signed | failed | rejected
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            // What KRA returns on signing.
            $table->string('kra_invoice_number', 60)->nullable();
            $table->string('kra_signature', 120)->nullable();
            $table->string('kra_internal_data', 255)->nullable();
            $table->text('qr_payload')->nullable();
            $table->string('scu_id', 40)->nullable();
            $table->timestampTz('kra_signed_at')->nullable();
            $table->jsonb('request')->nullable();
            $table->jsonb('response')->nullable();
            $table->timestampsTz();

            $table->unique(['document_type', 'document_id']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['document_number']);
        });

        DB::unprepared(<<<'SQL'
            -- Once KRA has signed a document the record is final.
            CREATE OR REPLACE FUNCTION etims_submissions_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'eTIMS submissions cannot be deleted';
                END IF;
                IF OLD.status = 'signed' THEN
                    RAISE EXCEPTION 'a signed eTIMS submission cannot be changed';
                END IF;
                IF (NEW.document_type, NEW.document_id, NEW.document_number, NEW.branch_id)
                   IS DISTINCT FROM (OLD.document_type, OLD.document_id, OLD.document_number, OLD.branch_id) THEN
                    RAISE EXCEPTION 'eTIMS submission identity cannot be changed';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER etims_submissions_guard BEFORE UPDATE OR DELETE ON etims_submissions
                FOR EACH ROW EXECUTE FUNCTION etims_submissions_guard();

            -- Documents created before the outbox existed get their eTIMS job too.
            INSERT INTO etims_submissions (document_type, document_id, document_number, branch_id, status, next_attempt_at, created_at, updated_at)
            SELECT 'sale', id, number, branch_id, 'pending', now(), now(), now() FROM sales;

            INSERT INTO etims_submissions (document_type, document_id, document_number, original_document_number, branch_id, status, next_attempt_at, created_at, updated_at)
            SELECT 'credit_note', r.id, r.number, s.number, r.branch_id, 'pending', now(), now(), now()
            FROM sale_returns r JOIN sales s ON s.id = r.sale_id;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS etims_submissions_guard ON etims_submissions');
        DB::unprepared('DROP FUNCTION IF EXISTS etims_submissions_guard()');
        Schema::dropIfExists('etims_submissions');
    }
};
