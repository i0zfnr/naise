<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\StaffOrderController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\DemoAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::post('/logout', [GoogleAuthController::class, 'logout'])->name('logout');
Route::get('/demo/login', [DemoAuthController::class, 'show'])->name('demo.login');
Route::post('/demo/login', [DemoAuthController::class, 'login'])
    ->middleware('throttle:admin-login')
    ->name('demo.login.submit');

Route::prefix('api')->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'show'])->middleware('throttle:60,1');
    Route::put('/profile', [ProfileController::class, 'update'])->middleware('throttle:profile-update');
    Route::post('/vouchers/redeem', [ProfileController::class, 'redeem'])->middleware('throttle:voucher-redeem');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:order-create');
    Route::get('/payment-settings', [OrderController::class, 'paymentSettings']);
    Route::post('/orders/{order}/receipt', [OrderController::class, 'receipt'])->middleware('throttle:receipt-upload');
    Route::get('/orders/{order}/payment-status', [OrderController::class, 'paymentStatus'])->middleware('throttle:60,1');
    Route::middleware('admin')->get('/admin/overview', [AdminAuthController::class, 'overview']);
});

Route::get('/admin', [AdminAuthController::class, 'show'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'login'])
    ->middleware('throttle:admin-login')
    ->name('admin.login.submit');
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])
    ->middleware('admin')
    ->name('admin.logout');
Route::post('/admin/store-mode', [AdminAuthController::class, 'enterStoreMode'])
    ->middleware('throttle:store-mode')
    ->name('admin.store-mode');
Route::post('/admin/store-mode/exit', [AdminAuthController::class, 'exitStoreMode'])
    ->name('admin.store-mode.exit');

Route::middleware('admin')->group(function (): void {
    Route::get('/admin/dashboard', [AdminAuthController::class, 'dashboard'])->name('admin.dashboard');
    Route::post('/admin/payment-settings/qr', [PaymentSettingsController::class, 'updateQr'])->middleware('throttle:staff-actions')->name('admin.payment-settings.qr');
});

Route::middleware(['staff', 'throttle:staff-actions'])->group(function (): void {
    Route::get('/staff/orders', [StaffOrderController::class, 'index'])->name('staff.orders');
    Route::delete('/staff/orders/{order}', [StaffOrderController::class, 'destroy'])->name('staff.orders.delete');
    Route::get('/staff/orders/{order}/receipt', [StaffOrderController::class, 'receipt'])->name('staff.orders.receipt');
    Route::post('/staff/orders/{order}/verify-receipt', [StaffOrderController::class, 'verifyReceipt'])->name('staff.orders.verify-receipt');
    Route::post('/staff/orders/{order}/mark-cash-received', [StaffOrderController::class, 'markCashReceived'])->name('staff.orders.mark-cash-received');
    Route::post('/staff/orders/{order}/mark-ready', [StaffOrderController::class, 'markReady'])->name('staff.orders.mark-ready');
});
