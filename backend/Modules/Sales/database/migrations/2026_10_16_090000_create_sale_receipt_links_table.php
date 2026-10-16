<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shareable receipt links (/r/{token}, e.g. sent on WhatsApp). Only the SHA-256 hash of the
     * random token is stored, so the table cannot be used to rebuild a link. Append-only: a link is
     * never edited or removed; each share makes a new one.
     */
    public function up(): void
    {
        Schema::create('sale_receipt_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index('sale_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sale_receipt_links_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'sale_receipt_links is append-only (% blocked)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER sale_receipt_links_no_update_delete
                BEFORE UPDATE OR DELETE ON sale_receipt_links
                FOR EACH ROW EXECUTE FUNCTION sale_receipt_links_block_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_receipt_links');
        DB::unprepared('DROP FUNCTION IF EXISTS sale_receipt_links_block_mutation()');
    }
};
