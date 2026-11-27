<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash checks on a till that stays open (Settings → Sales → All waiters share one till):
     * a manager counts the drawer without closing the till. The variance is booked to the
     * check, and the next check (or the close) counts from what was found. Append-only: only
     * the sign-off columns may be filled in, once.
     */
    public function up(): void
    {
        Schema::create('cash_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('till_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // manager who counted
            $table->timestampTz('period_started_at');                         // last count (or the till opening)
            $table->bigInteger('expected_cash_cents');
            $table->unsignedBigInteger('counted_cash_cents');
            $table->bigInteger('variance_cents');
            $table->jsonb('count_breakdown')->nullable();                     // denomination (cents) => pieces
            // Where the next period starts: the shift's running totals and the last sale and payment counted.
            $table->unsignedBigInteger('drops_total_cents');
            $table->unsignedBigInteger('payouts_total_cents');
            $table->unsignedBigInteger('last_sale_id')->nullable();
            $table->unsignedBigInteger('last_tender_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['shift_id', 'created_at']);
            $table->index(['branch_id', 'reviewed_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_checks_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.reviewed_at IS NULL
                   AND (NEW.shift_id, NEW.branch_id, NEW.till_id, NEW.user_id, NEW.period_started_at, NEW.expected_cash_cents,
                        NEW.counted_cash_cents, NEW.variance_cents, NEW.count_breakdown, NEW.drops_total_cents,
                        NEW.payouts_total_cents, NEW.last_sale_id, NEW.last_tender_id, NEW.note, NEW.created_at)
                       IS NOT DISTINCT FROM
                       (OLD.shift_id, OLD.branch_id, OLD.till_id, OLD.user_id, OLD.period_started_at, OLD.expected_cash_cents,
                        OLD.counted_cash_cents, OLD.variance_cents, OLD.count_breakdown, OLD.drops_total_cents,
                        OLD.payouts_total_cents, OLD.last_sale_id, OLD.last_tender_id, OLD.note, OLD.created_at) THEN
                    RETURN NEW; -- the one-time manager sign-off
                END IF;
                RAISE EXCEPTION 'cash checks are append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_checks_guard BEFORE UPDATE OR DELETE ON cash_checks FOR EACH ROW EXECUTE FUNCTION cash_checks_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cash_checks_guard ON cash_checks');
        DB::unprepared('DROP FUNCTION IF EXISTS cash_checks_guard()');
        Schema::dropIfExists('cash_checks');
    }
};
