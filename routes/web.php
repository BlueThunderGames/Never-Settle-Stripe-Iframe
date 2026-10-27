<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;

Route::get('/', [PaymentController::class, 'show'])->name('payments.show');

Route::post('/payments/quote', [PaymentController::class, 'quote'])
    ->middleware('throttle:60,1')
    ->name('payments.quote');
