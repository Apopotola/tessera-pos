<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sell by tot: a variant with a tot size is also poured from an open bottle at the bar. */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedSmallInteger('tot_ml')->nullable()->after('volume_ml');
        });

        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_tot_fits_bottle CHECK (tot_ml IS NULL OR (tot_ml > 0 AND tot_ml < volume_ml))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS product_variants_tot_fits_bottle');
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('tot_ml');
        });
    }
};
