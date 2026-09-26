<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash control (requirements: "Cash drops … recorded during the shift with a manager
     * witness. Closing: blind count by denomination → expected vs counted → variance with
     * reason → manager signs off").
     */
    public function up(): void
    {
        // Excess cash moved from the drawer to the safe. Append-only.
        Schema::create('cash_drops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();          // cashier
            $table->foreignId('witnessed_by')->constrained('users')->restrictOnDelete(); // manager PIN
            $table->unsignedBigInteger('amount_cents');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['shift_id']);
        });

        DB::statement('ALTER TABLE cash_drops ADD CONSTRAINT cash_drops_positive CHECK (amount_cents > 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_drops_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'cash drops are append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_drops_guard BEFORE UPDATE OR DELETE ON cash_drops FOR EACH ROW EXECUTE FUNCTION cash_drops_guard();
        SQL);

        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('drops_cents')->default(0);        // total dropped to the safe
            $table->jsonb('count_breakdown')->nullable();                 // denomination (cents) => pieces
            $table->text('variance_reason')->nullable();                  // cashier, after seeing the variance
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->index(['closed_at', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['closed_at', 'reviewed_at']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['drops_cents', 'count_breakdown', 'variance_reason', 'reviewed_at', 'review_note']);
        });
        DB::unprepared('DROP TRIGGER IF EXISTS cash_drops_guard ON cash_drops');
        DB::unprepared('DROP FUNCTION IF EXISTS cash_drops_guard()');
        Schema::dropIfExists('cash_drops');
    }
};
