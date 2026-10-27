<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;

Route::get('/', [PaymentController::class, 'show'])->name('payments.show');

Route::post('/payments/quote', [PaymentController::class, 'quote'])
    ->middleware('throttle:60,1')
    ->name('payments.quote');

Route::post('/payments/attempts', [PaymentController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('payments.attempts.store');

Route::get('/payments/attempts/{attempt}', [PaymentController::class, 'status'])
    ->middleware('throttle:120,1')
    ->name('payments.attempts.status');

Route::get('/payments/return/{attempt}', [PaymentController::class, 'complete'])
    ->middleware('throttle:60,1')
    ->name('payments.return');
