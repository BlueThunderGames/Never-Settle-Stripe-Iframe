<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentQuoteRequest;
use App\Services\FeeCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function show(): View
    {
        return view('payments.form');
    }

    /**
     * Price an invoice amount for the chosen payment type, fee included.
     */
    public function quote(PaymentQuoteRequest $request, FeeCalculator $fees): JsonResponse
    {
        return response()->json(
            $fees->quote($request->amountCents(), $request->paymentType())
        );
    }
}
