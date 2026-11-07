<?php

use App\Http\Controllers\AccessReadController;
use App\Http\Controllers\AccountReactivationController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CustomerAccountController;
use App\Http\Controllers\CustomerAddressChangeController;
use App\Http\Controllers\CustomerApprovalDecisionController;
use App\Http\Controllers\CustomerIdentityChangeController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\CustomerRegistrationController;
use App\Http\Controllers\CustomerReviewController;
use App\Http\Controllers\EmployeeAccessResetController;
use App\Http\Controllers\EmployeeProvisioningController;
use App\Http\Controllers\RoleCatalogController;
use App\Http\Controllers\RoleDeletionController;
use App\Http\Controllers\RoleEditorController;
use App\Http\Middleware\AuditCartRequest;
use App\Http\Middleware\AuditCustomerApprovalDecision;
use App\Http\Middleware\AuditCustomerOrderRequest;
use App\Http\Middleware\AuditCustomerRegistration;
use App\Http\Middleware\EnsureCustomerApproved;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/me/orders')->middleware(['throttle:customer-commerce', AuditCustomerOrderRequest::class, 'auth:sanctum', EnsureCustomerApproved::class])->group(function (): void {
    Route::get('/', [CustomerOrderController::class, 'index'])->name('customer.orders.index');
    Route::post('/', [CustomerOrderController::class, 'store'])->name('customer.orders.store');
    Route::get('/{order}', [CustomerOrderController::class, 'show'])->whereNumber('order')->name('customer.orders.show');
});

Route::prefix('v1/me/cart')->middleware(['throttle:customer-commerce', AuditCartRequest::class, 'auth:sanctum', EnsureCustomerApproved::class])->group(function (): void {
    Route::get('/', [CartController::class, 'show'])->name('cart.show');
    Route::put('/products/{product}', [CartController::class, 'putLine'])->whereNumber('product')->name('cart.lines.put');
    Route::delete('/lines/{line}', [CartController::class, 'destroyLine'])->whereNumber('line')->name('cart.lines.destroy');
});

Route::get('/v1/user', function (Request $request): JsonResponse {
    return response()->json([
        'data' => $request->user()->only(['id', 'name', 'email']),
    ]);
})->middleware(['auth:sanctum', EnsureCustomerApproved::class]);

Route::post('/v1/customer-registrations', CustomerRegistrationController::class)
    ->middleware(AuditCustomerRegistration::class);

Route::middleware(['auth:sanctum', EnsureCustomerApproved::class, 'can:CUSTOMER_APPROVE'])->group(function (): void {
    Route::post('/v1/customers/{customerProfile}/approval-decisions', CustomerApprovalDecisionController::class)
        ->middleware(AuditCustomerApprovalDecision::class);
    Route::get('/v1/customer-registrations', [CustomerReviewController::class, 'index']);
    Route::get('/v1/customer-registrations/{customerProfile}', [CustomerReviewController::class, 'show']);
});

Route::get('/v1/customer-account', CustomerAccountController::class)
    ->middleware(['auth:sanctum', EnsureCustomerApproved::class]);

Route::get('/v1/roles', RoleCatalogController::class)
    ->middleware(['auth:sanctum', EnsureCustomerApproved::class, 'can:USER_ASSIGN_ROLE']);

Route::post('/v1/users', [EmployeeProvisioningController::class, 'store'])
    ->middleware(['auth:sanctum', EnsureCustomerApproved::class, 'can:USER_CREATE', 'can:USER_ASSIGN_ROLE']);
Route::get('/v1/employee-access-deliveries/{delivery}', [EmployeeProvisioningController::class, 'show'])
    ->middleware(['auth:sanctum', EnsureCustomerApproved::class, 'can:USER_RESET_ACCESS']);

Route::post('/v1/users/{user}/access-resets', EmployeeAccessResetController::class)
    ->middleware(['auth:sanctum', EnsureCustomerApproved::class, 'can:USER_RESET_ACCESS']);

Route::middleware(['auth:sanctum', EnsureCustomerApproved::class, 'can:ROLE_MANAGE'])->group(function (): void {
    Route::get('/v1/access/roles', [AccessReadController::class, 'roles']);
    Route::post('/v1/access/roles', [RoleEditorController::class, 'store']);
    Route::patch('/v1/access/roles/{role}', [RoleEditorController::class, 'rename'])->whereUuid('role');
    Route::post('/v1/access/roles/{role}/permission-change-preview', [RoleEditorController::class, 'preview'])->whereUuid('role');
    Route::put('/v1/access/roles/{role}/permissions', [RoleEditorController::class, 'confirm'])->whereUuid('role');
    Route::get('/v1/access/permissions', [AccessReadController::class, 'permissions']);
});

Route::get('/v1/me/permissions', [AccessReadController::class, 'ownPermissions'])
    ->middleware(['auth:sanctum', EnsureCustomerApproved::class]);

Route::middleware(['auth:sanctum', EnsureCustomerApproved::class, 'can:ROLE_DELETE'])->group(function (): void {
    Route::get('/v1/access/roles/{role}/deletion-preview', [RoleDeletionController::class, 'preview'])->whereUuid('role');
    Route::delete('/v1/access/roles/{role}', [RoleDeletionController::class, 'destroy'])->whereUuid('role');
});

Route::get('/v1/me/profile', [CustomerProfileController::class, 'show'])
    ->middleware(['auth:sanctum', EnsureCustomerApproved::class])->name('customer.profile.show');

Route::patch('/v1/me/profile/phone', [CustomerProfileController::class, 'updatePhone'])
    ->middleware(['auth:sanctum', EnsureCustomerApproved::class])->name('customer.profile.phone.update');

Route::middleware(['auth:sanctum', EnsureCustomerApproved::class])->group(function (): void {
    Route::get('/v1/me/profile/address-change-requests', [CustomerAddressChangeController::class, 'ownIndex']);
    Route::post('/v1/me/profile/address-change-requests', [CustomerAddressChangeController::class, 'store']);
});

Route::middleware(['auth:sanctum', EnsureCustomerApproved::class, 'can:CUSTOMER_APPROVE'])->group(function (): void {
    Route::get('/v1/customer-address-changes', [CustomerAddressChangeController::class, 'staffIndex']);
    Route::get('/v1/customer-address-changes/{change}', [CustomerAddressChangeController::class, 'show'])->whereUuid('change');
    Route::post('/v1/customer-address-changes/{change}/decisions', [CustomerAddressChangeController::class, 'decide'])->whereUuid('change');
});

Route::middleware(['auth:sanctum', EnsureCustomerApproved::class])->group(function (): void {
    Route::get('/v1/me/profile/identity-change-requests', [CustomerIdentityChangeController::class, 'ownIndex']);
    Route::post('/v1/me/profile/identity-change-requests', [CustomerIdentityChangeController::class, 'store']);
    Route::post('/v1/me/profile/identity-change-requests/{change}/verification', [CustomerIdentityChangeController::class, 'resend'])->whereUuid('change')->middleware('throttle:6,1');
});

Route::middleware(['auth:sanctum', EnsureCustomerApproved::class, 'can:CUSTOMER_APPROVE'])->group(function (): void {
    Route::get('/v1/customer-identity-changes', [CustomerIdentityChangeController::class, 'staffIndex']);
    Route::get('/v1/customer-identity-changes/{change}', [CustomerIdentityChangeController::class, 'show'])->whereUuid('change');
    Route::post('/v1/customer-identity-changes/{change}/decisions', [CustomerIdentityChangeController::class, 'decide'])->whereUuid('change');
});

Route::get('/v1/customer-identity-changes/{change}/verify-email', [CustomerIdentityChangeController::class, 'verifyEmail'])
    ->whereUuid('change')->middleware(['signed', 'throttle:6,1'])->name('customer.identity.email.verify');

Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::get('/v1/me/reactivation-requests', [AccountReactivationController::class, 'ownIndex'])->name('account.reactivation.index');
    Route::post('/v1/me/reactivation-requests', [AccountReactivationController::class, 'store'])
        ->middleware('throttle:6,1')->name('account.reactivation.store');
});

Route::middleware(['auth:sanctum', EnsureCustomerApproved::class, 'can:CUSTOMER_APPROVE'])->group(function (): void {
    Route::get('/v1/account-reactivations', [AccountReactivationController::class, 'staffIndex']);
    Route::get('/v1/account-reactivations/{reactivation}', [AccountReactivationController::class, 'show'])->whereUuid('reactivation');
    Route::post('/v1/account-reactivations/{reactivation}/decisions', [AccountReactivationController::class, 'decide'])->whereUuid('reactivation');
});
