<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Settings → Approvals → Refund "Allowed; logged": a return may have no approving manager. */
    public function up(): void
    {
        DB::statement('ALTER TABLE sale_returns ALTER COLUMN approved_by DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sale_returns ALTER COLUMN approved_by SET NOT NULL');
    }
};
