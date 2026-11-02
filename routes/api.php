<?php

use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\PaymentCompanionController;
use App\Http\Controllers\Api\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'ok' => true,
    'service' => 'Naise Coffee v3 API',
]));

Route::get('/menu', [MenuController::class, 'index']);
Route::post('/payments/webhook', [PaymentWebhookController::class, 'handle'])->middleware('throttle:payment-webhook');
Route::post('/companion/login', [PaymentCompanionController::class, 'login'])->middleware('throttle:companion-login');
Route::middleware('throttle:60,1')->prefix('companion')->group(function (): void {
    Route::post('/logout', [PaymentCompanionController::class, 'logout']);
    Route::post('/events', [PaymentCompanionController::class, 'store']);
    Route::post('/events/{event}/confirm', [PaymentCompanionController::class, 'confirm'])->whereNumber('event');
});
