<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash rounding (Settings → Payments): the cash taken minus the cash due. The sale total
     * (and its VAT) stays exact; the drawer holds the rounded amount.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->integer('rounding_cents')->default(0)->after('total_cents');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('rounding_cents');
        });
    }
};
