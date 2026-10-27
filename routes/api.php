<?php

use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'ok' => true,
    'service' => 'Naise Coffee v3 API',
]));

Route::get('/menu', [MenuController::class, 'index']);
Route::post('/payments/webhook', [PaymentWebhookController::class, 'handle'])->middleware('throttle:payment-webhook');
