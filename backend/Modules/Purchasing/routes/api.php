<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use Modules\Purchasing\Http\Controllers\SupplierController;
use Modules\Purchasing\Http\Controllers\SupplierDocumentController;

/*
|--------------------------------------------------------------------------
| Purchasing API routes
|--------------------------------------------------------------------------
| Mounted at /api/v1 by the module RouteServiceProvider.
| Workflow permissions, branch access and maker–checker live in the services.
*/

Route::middleware('auth:sanctum')->prefix('purchasing')->name('purchasing.')->group(function () {
    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::get('suppliers/{supplier}/items', [SupplierController::class, 'items'])->name('suppliers.items');

    Route::get('orders', [PurchaseOrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [PurchaseOrderController::class, 'store'])->name('orders.store');
    Route::get('orders/{order}', [PurchaseOrderController::class, 'show'])->name('orders.show');
    Route::put('orders/{order}', [PurchaseOrderController::class, 'update'])->name('orders.update');
    Route::post('orders/{order}/approve', [PurchaseOrderController::class, 'approve'])->name('orders.approve');
    Route::post('orders/{order}/send', [PurchaseOrderController::class, 'send'])->name('orders.send');
    Route::post('orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('orders/{order}/receive', [PurchaseOrderController::class, 'receive'])->name('orders.receive');
    Route::get('receipts', [PurchaseOrderController::class, 'receipts'])->name('receipts.index');

    Route::get('invoices', [SupplierDocumentController::class, 'invoices'])->name('invoices.index');
    Route::post('invoices', [SupplierDocumentController::class, 'storeInvoice'])->name('invoices.store');

    Route::get('returns', [SupplierDocumentController::class, 'returns'])->name('returns.index');
    Route::post('returns', [SupplierDocumentController::class, 'storeReturn'])->name('returns.store');
    Route::post('returns/{supplierReturn}/approve', [SupplierDocumentController::class, 'approveReturn'])->name('returns.approve');
    Route::post('returns/{supplierReturn}/reject', [SupplierDocumentController::class, 'rejectReturn'])->name('returns.reject');
    Route::post('returns/{supplierReturn}/credit-note', [SupplierDocumentController::class, 'creditNote'])->name('returns.credit-note');
});
