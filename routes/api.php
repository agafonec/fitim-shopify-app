<?php

use App\Http\Controllers\Api\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/products', [ProductController::class, 'index'])->name('api.products.index');
    Route::get('/products/lookup', [ProductController::class, 'lookup'])->name('api.products.lookup');
    Route::get('/products/inventory', [ProductController::class, 'inventory'])->name('api.products.inventory');
    Route::get('/products/inventory/lookup', [ProductController::class, 'inventoryLookup'])->name('api.products.inventory.lookup');
});
