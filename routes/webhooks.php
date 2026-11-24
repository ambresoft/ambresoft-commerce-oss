<?php

use App\Payment\Presentation\Http\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

// Server-to-server provider notifications: no session, cookies or CSRF token (see PaymentWebhookController).
Route::post('/payments/webhooks/{method}', PaymentWebhookController::class)
    ->where('method', '[a-z][a-z0-9_.-]{1,59}')->middleware('throttle:600,1')->name('payments.webhook');
