<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expenses and petty cash (Phase 2). Back-office expenses wait for a manager's approval
     * (maker–checker); cash paid out of a till drawer is witnessed with a manager's PIN and
     * lowers the cash expected at cash-up. Approved expenses are never edited: a reversal row
     * (negative amount) corrects them.
     */
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        $names = [
            'Transport & delivery', 'Casual labour', 'Cleaning & supplies', 'Electricity & water', 'Repairs & maintenance',
            'Airtime & internet', 'Security', 'Rent', 'Licences & permits', 'Bank & M-PESA charges', 'Staff meals', 'Other',
        ];
        foreach ($names as $i => $name) {
            DB::table('expense_categories')->insert(['name' => $name, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('paid_from', 20);  // till | petty_cash | bank | mpesa
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete(); // paid out of a till drawer
            $table->string('payee', 120)->nullable();
            $table->string('description', 255);
            $table->string('reference', 60)->nullable();  // receipt / transaction number
            $table->date('spent_on');
            $table->string('status', 20);      // pending | approved | rejected
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('expenses')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['branch_id', 'status', 'spent_on']);
            $table->index(['shift_id']);
        });

        DB::unprepared(<<<'SQL'
            -- Only a pending expense may change (approve / reject). Nothing is ever deleted.
            CREATE OR REPLACE FUNCTION expenses_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR OLD.status <> 'pending' THEN
                    RAISE EXCEPTION 'expenses are kept as recorded; reverse an approved expense instead';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER expenses_guard BEFORE UPDATE OR DELETE ON expenses FOR EACH ROW EXECUTE FUNCTION expenses_guard();
        SQL);

        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('payouts_cents')->default(0); // cash paid out of the drawer for expenses
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('payouts_cents');
        });
        DB::unprepared('DROP TRIGGER IF EXISTS expenses_guard ON expenses');
        DB::unprepared('DROP FUNCTION IF EXISTS expenses_guard()');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
