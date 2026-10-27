<?php

use App\Http\Controllers\PaymentAttemptController;
use App\Http\Controllers\PaymentFormController;
use App\Http\Controllers\PaymentQuoteController;
use App\Http\Controllers\PaymentReturnController;
use Illuminate\Support\Facades\Route;

Route::get('/', PaymentFormController::class)->name('payments.show');

Route::post('/payments/quote', PaymentQuoteController::class)
    ->middleware('throttle:60,1')
    ->name('payments.quote');

Route::post('/payments/attempts', [PaymentAttemptController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('payments.attempts.store');

Route::get('/payments/attempts/{attempt}', [PaymentAttemptController::class, 'status'])
    ->middleware('throttle:120,1')
    ->name('payments.attempts.status');

Route::get('/payments/return/{attempt}', PaymentReturnController::class)
    ->middleware('throttle:60,1')
    ->name('payments.return');
