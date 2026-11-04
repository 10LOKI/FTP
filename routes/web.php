<?php

use App\Http\Controllers\CustomerSessionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicCatalogueController;
use App\Http\Controllers\PublicContactController;
use App\Http\Middleware\EnsureCustomerApproved;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicCatalogueController::class, 'home'])->name('public.home');
Route::get('/catalogue', [PublicCatalogueController::class, 'catalogue'])->name('public.catalogue');
Route::get('/catalogue/{product}', [PublicCatalogueController::class, 'detail'])
    ->whereNumber('product')->name('public.product');
Route::view('/contact', 'public-contact')->name('public.contact');
Route::view('/connexion', 'public-account', ['mode' => 'login'])->name('login');
Route::view('/demande-compte', 'public-account', ['mode' => 'register'])->name('register');
Route::post('/contact', [PublicContactController::class, 'store'])
    ->middleware('throttle:5,1')->name('public.contact.store');

Route::prefix('api/v1/products')->middleware(['auth', EnsureCustomerApproved::class])->group(function (): void {
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

Route::post('/api/v1/login', [CustomerSessionController::class, 'store'])->middleware('throttle:customer-login');
Route::post('/api/v1/logout', [CustomerSessionController::class, 'destroy'])->middleware('auth:sanctum');

Route::get('/api/v1/catalogue/products', [PublicCatalogueController::class, 'index'])->name('public.products.index');
Route::get('/api/v1/catalogue/products/{product}', [PublicCatalogueController::class, 'show'])
    ->whereNumber('product')->name('public.products.show');
