<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalogue structure: Brand → Product → Variant (sellable pack size) → Pack (case/crate).
     * A variant is the stock-keeping and selling unit; every barcode points at exactly one variant.
     */
    public function up(): void
    {
        // eTIMS tax types. Codes/rates REQUIRE VALIDATION against the KRA OSCU/VSCU v2.0 spec.
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name', 60);
            $table->unsignedInteger('rate_bp'); // basis points: 1600 = 16%
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('country', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX brands_name_lower_unique ON brands (lower(name))');

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->decimal('abv', 4, 1)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['category_id', 'is_active']);
        });
        DB::statement('CREATE UNIQUE INDEX products_brand_name_lower_unique ON products (coalesce(brand_id, 0), lower(name))');

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('volume_ml');
            $table->string('container', 20); // Container enum
            $table->string('sku', 40)->unique();
            $table->foreignId('tax_rate_id')->constrained()->restrictOnDelete();
            $table->string('etims_item_class_code', 20)->nullable();
            $table->string('etims_item_code', 40)->nullable();
            $table->boolean('track_batches')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['product_id', 'volume_ml', 'container']);
        });

        Schema::create('packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('name', 40);
            $table->unsignedInteger('units');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['variant_id', 'units']);
        });

        // One code space for bottle and pack barcodes: a scan resolves to exactly one variant (+ pack).
        Schema::create('barcodes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('pack_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcodes');
        Schema::dropIfExists('packs');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('tax_rates');
    }
};
