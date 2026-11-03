<?php

use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('api/v1/products')->middleware('auth')->group(function (): void {
    Route::get('/{product}', [ProductController::class, 'show']);
    Route::get('/{product}/purchase-price', [ProductController::class, 'purchasePrice']);
    Route::patch('/{product}/references/{reference}/verification', [ProductController::class, 'verifyReference'])
        ->whereNumber('reference');
    Route::post('/', [ProductController::class, 'store']);
    Route::put('/{product}', [ProductController::class, 'update']);
    Route::put('/{product}/logistics', [ProductController::class, 'logistics']);
    Route::patch('/{product}/purchase-price', [ProductController::class, 'updatePurchasePrice']);
    Route::patch('/{product}/price', [ProductController::class, 'price']);
    Route::patch('/{product}/publication', [ProductController::class, 'publication']);
});
