<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\CustomerPricingController;
use App\Http\Controllers\CustomerSessionController;
use App\Http\Controllers\DeliveryPreparationController;
use App\Http\Controllers\EmployeeSessionController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoicePreviewController;
use App\Http\Controllers\NotificationDeliveryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductReferenceController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\PublicCatalogueController;
use App\Http\Controllers\PublicContactController;
use App\Http\Controllers\SalesReviewController;
use App\Http\Controllers\SupplierReturnController;
use App\Http\Controllers\WarehouseStockController;
use App\Http\Middleware\EnsureCustomerApproved;
use Illuminate\Support\Facades\Route;

Route::get('/gestion/commandes', [SalesReviewController::class, 'page'])
    ->middleware(['auth', EnsureCustomerApproved::class])->name('sales.review.page');

Route::get('/', [PublicCatalogueController::class, 'home'])->name('public.home');

Route::middleware(['auth', EnsureCustomerApproved::class])->group(function (): void {
    Route::get('/gestion/retours-fournisseurs', [SupplierReturnController::class, 'page'])->name('supplier-returns.page');
    Route::prefix('api/v1/supplier-returns')->middleware('throttle:customer-commerce')->group(function (): void {
        Route::get('/', [SupplierReturnController::class, 'index']);
        Route::post('/suppliers', [SupplierReturnController::class, 'mutate'])->defaults('action', 'supplier');
        Route::post('/purchases', [SupplierReturnController::class, 'mutate'])->defaults('action', 'purchase');
        Route::post('/adjustments', [SupplierReturnController::class, 'mutate'])->defaults('action', 'adjustment');
        Route::post('/', [SupplierReturnController::class, 'mutate'])->defaults('action', 'request');
        Route::post('/{return}/dispatch', [SupplierReturnController::class, 'mutate'])->whereUuid('return')->defaults('action', 'dispatch');
        Route::post('/{return}/credit', [SupplierReturnController::class, 'mutate'])->whereUuid('return')->defaults('action', 'credit');
        Route::get('/{return}/document', [SupplierReturnController::class, 'document'])->whereUuid('return');
    });
    Route::get('/gestion/preparations', [DeliveryPreparationController::class, 'page'])->name('delivery.page');
    Route::prefix('api/v1/deliveries')->middleware('throttle:customer-commerce')->name('delivery.')->group(function (): void {
        Route::get('/ready', [DeliveryPreparationController::class, 'ready'])->name('ready');
        Route::get('/batches', [DeliveryPreparationController::class, 'index'])->name('index');
        Route::post('/claim', [DeliveryPreparationController::class, 'mutate'])->defaults('action', 'claim')->name('claim');
        Route::post('/batches/{batch}/scan', [DeliveryPreparationController::class, 'mutate'])->whereUuid('batch')->defaults('action', 'scan')->name('scan');
        Route::post('/batches/{batch}/prepare', [DeliveryPreparationController::class, 'mutate'])->whereUuid('batch')->defaults('action', 'prepare')->name('prepare');
        Route::post('/batches/{batch}/transfer', [DeliveryPreparationController::class, 'mutate'])->whereUuid('batch')->defaults('action', 'transfer')->name('transfer');
        Route::post('/batches/{batch}/dispatch', [DeliveryPreparationController::class, 'mutate'])->whereUuid('batch')->defaults('action', 'dispatch')->name('dispatch');
        Route::get('/batches/{batch}/document', [DeliveryPreparationController::class, 'document'])->whereUuid('batch')->name('document');
    });
    Route::get('/gestion/stocks', [WarehouseStockController::class, 'page'])->name('stock.page');
    Route::prefix('api/v1/stock')->name('stock.')->group(function (): void {
        Route::get('/warehouses', [WarehouseStockController::class, 'structure'])->name('warehouses');
        Route::get('/locations', [WarehouseStockController::class, 'locations'])->name('locations');
        Route::get('/products', [WarehouseStockController::class, 'products'])->name('products');
        Route::get('/balances', [WarehouseStockController::class, 'index'])->name('balances');
        Route::get('/movements', [WarehouseStockController::class, 'movements'])->name('movements');
        Route::get('/alerts', [WarehouseStockController::class, 'alerts'])->name('alerts');
        Route::post('/actions/{action}', [WarehouseStockController::class, 'mutate'])
            ->whereIn('action', ['warehouse', 'location', 'configure', 'receive', 'adjust', 'inspect', 'transfer'])
            ->middleware('throttle:30,1')->name('mutate');
    });
});
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

Route::middleware(['auth', EnsureCustomerApproved::class, 'throttle:customer-commerce'])->group(function (): void {
    Route::get('/gestion/facturation', [InvoicePreviewController::class, 'page'])->name('invoice-preview.page');
    Route::get('/api/v1/billing/deliveries', [InvoicePreviewController::class, 'index'])->name('invoice-preview.index');
    Route::get('/api/v1/billing/deliveries/{batch}', [InvoicePreviewController::class, 'source'])->whereUuid('batch')->name('invoice-preview.delivery');
    Route::post('/api/v1/billing/preview', [InvoicePreviewController::class, 'preview'])->name('invoice-preview.preview');
});

Route::middleware(['auth', EnsureCustomerApproved::class, 'throttle:customer-commerce'])->group(function (): void {
    Route::post('/api/v1/billing/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('/api/v1/invoices/{invoice}', [InvoiceController::class, 'show'])->whereUuid('invoice')->name('invoices.show');
    Route::get('/api/v1/invoices/{invoice}/document', [InvoiceController::class, 'document'])->whereUuid('invoice')->name('invoices.document');
});
