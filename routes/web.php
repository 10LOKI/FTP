<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\CustomerPricingController;
use App\Http\Controllers\CustomerSessionController;
use App\Http\Controllers\EmployeeSessionController;
use App\Http\Controllers\NotificationDeliveryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductReferenceController;
use App\Http\Controllers\PromotionController;
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
Route::get('/gestion/produits/{product}/references', [ProductReferenceController::class, 'edit'])
    ->middleware(['auth', EnsureCustomerApproved::class])->name('product.references.edit');

Route::get('/panier', [CartController::class, 'page'])->middleware(['auth', EnsureCustomerApproved::class])->name('cart.page');
Route::get('/mes-commandes', [CustomerOrderController::class, 'page'])->middleware(['auth', EnsureCustomerApproved::class])->name('customer.orders.page');

Route::prefix('api/v1/products')->middleware(['auth', EnsureCustomerApproved::class])->group(function (): void {
    Route::get('/{product}', [ProductController::class, 'show']);
    Route::get('/{product}/purchase-price', [ProductController::class, 'purchasePrice']);
    Route::patch('/{product}/references/{reference}/verification', [ProductController::class, 'verifyReference'])
        ->whereNumber('reference')->name('product.references.verify');
    Route::get('/{product}/references', [ProductReferenceController::class, 'index'])->name('product.references.index');
    Route::post('/{product}/references', [ProductReferenceController::class, 'store'])->name('product.references.store');
    Route::put('/{product}/original-reference', [ProductReferenceController::class, 'updateOriginal'])->name('product.references.original');
    Route::put('/{product}/references/{reference}', [ProductReferenceController::class, 'update'])
        ->whereNumber('reference')->name('product.references.update');
    Route::delete('/{product}/references/{reference}', [ProductReferenceController::class, 'destroy'])
        ->whereNumber('reference')->name('product.references.destroy');
    Route::post('/', [ProductController::class, 'store']);
    Route::put('/{product}', [ProductController::class, 'update']);
    Route::put('/{product}/logistics', [ProductController::class, 'logistics']);
    Route::patch('/{product}/purchase-price', [ProductController::class, 'updatePurchasePrice']);
    Route::patch('/{product}/price', [ProductController::class, 'price'])->name('product.price');
    Route::patch('/{product}/publication', [ProductController::class, 'publication']);
});

Route::post('/api/v1/login', [CustomerSessionController::class, 'store'])->middleware('throttle:customer-login');
Route::post('/api/v1/logout', [CustomerSessionController::class, 'destroy'])->middleware('auth:sanctum')->name('account.logout');

Route::post('/api/v1/employee-login', [EmployeeSessionController::class, 'store'])
    ->middleware('throttle:employee-login');
Route::post('/api/v1/temporary-password', [EmployeeSessionController::class, 'replacePassword'])
    ->middleware(['auth:sanctum', 'throttle:temporary-password'])->name('employee.password.replace');

Route::get('/api/v1/catalogue/products', [PublicCatalogueController::class, 'index'])->name('public.products.index');
Route::get('/api/v1/catalogue/products/{product}', [PublicCatalogueController::class, 'show'])
    ->whereNumber('product')->name('public.products.show');

Route::middleware(['auth', EnsureCustomerApproved::class])->group(function (): void {
    Route::get('/gestion/promotions', [PromotionController::class, 'edit'])->name('promotions.manage');
    Route::get('/gestion/remises-clients', [CustomerPricingController::class, 'edit'])->name('discounts.manage');
    Route::get('/promotions', [PublicCatalogueController::class, 'promotionsPage'])->name('customer.promotions');
    Route::get('/api/v1/promotions/products', [PromotionController::class, 'products'])->name('promotions.products');
    Route::get('/api/v1/promotions/settings', [PromotionController::class, 'settings'])->name('promotions.settings');
    Route::put('/api/v1/promotions/settings', [PromotionController::class, 'updateSettings']);
    Route::get('/api/v1/promotions', [PromotionController::class, 'index'])->name('promotions.index');
    Route::post('/api/v1/promotions', [PromotionController::class, 'store']);
    Route::put('/api/v1/promotions/{promotion}', [PromotionController::class, 'update']);
    Route::get('/api/v1/customer-discounts', [CustomerPricingController::class, 'customers'])->name('discounts.index');
    Route::put('/api/v1/customers/{customerProfile}/discount', [CustomerPricingController::class, 'updateDiscount'])->name('discounts.update');
    Route::get('/api/v1/me/promotions', [PublicCatalogueController::class, 'promotions'])->name('customer.promotions.index');
    Route::get('/api/v1/me/products/{product}/price', [CustomerPricingController::class, 'show'])->whereNumber('product')->name('customer.price');
});

Route::middleware(['auth', EnsureCustomerApproved::class])->group(function (): void {
    Route::get('/gestion/audit', [AuditController::class, 'page'])->name('audit.page');
    Route::get('/api/v1/audit-events/export', [AuditController::class, 'export'])->middleware('throttle:5,1')->name('audit.export');
    Route::get('/api/v1/audit-events', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/api/v1/notification-deliveries', [NotificationDeliveryController::class, 'index'])->name('notification-deliveries.index');
    Route::post('/api/v1/notification-deliveries/{kind}/{delivery}/retry', [NotificationDeliveryController::class, 'retry'])
        ->whereUuid('delivery')->middleware('throttle:30,1')->name('notification-deliveries.retry');
});
