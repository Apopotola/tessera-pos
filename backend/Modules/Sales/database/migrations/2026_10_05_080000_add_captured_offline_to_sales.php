<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offline till: a sale rung up without a connection and sent when it came back.
     * completed_at is the till's time of sale; created_at is when the server received it.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('captured_offline')->default(false);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sales_offline_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.captured_offline IS DISTINCT FROM OLD.captured_offline THEN
                    RAISE EXCEPTION 'completed sales cannot be edited; record a return instead';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER sales_offline_guard BEFORE UPDATE ON sales FOR EACH ROW EXECUTE FUNCTION sales_offline_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sales_offline_guard ON sales');
        DB::unprepared('DROP FUNCTION IF EXISTS sales_offline_guard()');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('captured_offline');
        });
    }
};
