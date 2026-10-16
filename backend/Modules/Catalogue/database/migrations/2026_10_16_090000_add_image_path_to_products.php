<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Product photo for the till grid and catalogue (stored on the local disk under products/). */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path', 120)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
