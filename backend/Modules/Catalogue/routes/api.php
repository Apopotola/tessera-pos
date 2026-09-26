<?php

use Illuminate\Support\Facades\Route;
use Modules\Catalogue\Http\Controllers\PriceController;
use Modules\Catalogue\Http\Controllers\ProductController;
use Modules\Catalogue\Http\Controllers\PromotionController;
use Modules\Catalogue\Http\Controllers\TaxonomyController;
use Modules\Catalogue\Http\Controllers\VariantController;

/*
|--------------------------------------------------------------------------
| Catalogue API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
| Permissions are checked in Form Requests (writes) and controllers (reads).
*/

Route::middleware('auth:sanctum')->prefix('catalogue')->name('catalogue.')->group(function () {
    Route::get('tax-rates', [TaxonomyController::class, 'taxRates'])->name('tax-rates.index');

    Route::get('brands', [TaxonomyController::class, 'brands'])->name('brands.index');
    Route::post('brands', [TaxonomyController::class, 'storeBrand'])->name('brands.store');
    Route::put('brands/{brand}', [TaxonomyController::class, 'updateBrand'])->name('brands.update');

    Route::get('categories', [TaxonomyController::class, 'categories'])->name('categories.index');
    Route::post('categories', [TaxonomyController::class, 'storeCategory'])->name('categories.store');
    Route::put('categories/{category}', [TaxonomyController::class, 'updateCategory'])->name('categories.update');

    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');

    Route::post('products/{product}/variants', [VariantController::class, 'store'])->name('variants.store');
    Route::put('variants/{variant}', [VariantController::class, 'update'])->name('variants.update');
    Route::post('variants/{variant}/barcodes', [VariantController::class, 'storeBarcode'])->name('barcodes.store');
    Route::delete('variants/{variant}/barcodes/{barcode}', [VariantController::class, 'destroyBarcode'])->scopeBindings()->name('barcodes.destroy');
    Route::post('variants/{variant}/packs', [VariantController::class, 'storePack'])->name('packs.store');
    Route::put('packs/{pack}', [VariantController::class, 'updatePack'])->name('packs.update');
    Route::get('lookup/{code}', [VariantController::class, 'lookup'])->where('code', '[A-Za-z0-9-]+')->name('lookup');

    Route::get('prices', [PriceController::class, 'index'])->name('prices.index');
    Route::get('variants/{variant}/prices', [PriceController::class, 'history'])->name('prices.history');
    Route::post('variants/{variant}/prices', [PriceController::class, 'store'])->name('prices.store');
    Route::post('prices/{price}/approve', [PriceController::class, 'approve'])->name('prices.approve');
    Route::post('prices/{price}/reject', [PriceController::class, 'reject'])->name('prices.reject');

    Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
    Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
    Route::post('promotions/{promotion}/approve', [PromotionController::class, 'approve'])->name('promotions.approve');
    Route::post('promotions/{promotion}/reject', [PromotionController::class, 'reject'])->name('promotions.reject');
    Route::post('promotions/{promotion}/end', [PromotionController::class, 'end'])->name('promotions.end');
});
