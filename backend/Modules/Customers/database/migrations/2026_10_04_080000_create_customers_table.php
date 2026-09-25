<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registered customers only: wholesale buyers and businesses that need their KRA PIN on
     * the eTIMS invoice. Walk-in sales have no customer and collect no personal data.
     * Personal fields can be anonymised; sales keep their own copy of the buyer PIN.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);                  // business name preferred over a person's
            $table->string('kra_pin', 11)->nullable();
            $table->boolean('is_wholesale')->default(false);
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 20)->nullable();      // +2547XXXXXXXX
            $table->string('email', 150)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('anonymised_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['is_active', 'name']);
        });

        DB::statement('CREATE UNIQUE INDEX customers_kra_pin_unique ON customers (kra_pin) WHERE kra_pin IS NOT NULL');

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['customer_id', 'completed_at']);
        });

        DB::unprepared(<<<'SQL'
            -- sales_guard() predates this column; keep the customer on a sale immutable too.
            CREATE OR REPLACE FUNCTION sales_customer_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.customer_id IS DISTINCT FROM OLD.customer_id THEN
                    RAISE EXCEPTION 'completed sales cannot be edited; record a return instead';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER sales_customer_guard BEFORE UPDATE ON sales FOR EACH ROW EXECUTE FUNCTION sales_customer_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sales_customer_guard ON sales');
        DB::unprepared('DROP FUNCTION IF EXISTS sales_customer_guard()');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::dropIfExists('customers');
    }
};
