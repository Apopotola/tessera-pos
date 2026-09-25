<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Navigation served to the frontend. `view_type` must match a key in
     * frontend/components/workspace/ViewRegistry.tsx.
     */
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('menus')->cascadeOnDelete();
            $table->string('key', 60)->unique();
            $table->string('title', 100);
            $table->string('icon', 60)->nullable();
            $table->string('view_type', 60)->nullable();
            $table->string('path', 120)->nullable();
            $table->string('permission', 100)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
