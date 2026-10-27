<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentQuoteRequest;
use App\Services\FeeCalculator;
use Illuminate\Http\JsonResponse;

class PaymentQuoteController extends Controller
{
    /**
     * Price an invoice amount for the chosen payment type, fee included.
     */
    public function __invoke(PaymentQuoteRequest $request, FeeCalculator $fees): JsonResponse
    {
        return response()->json(
            $fees->quote($request->amountCents(), $request->paymentType())
        );
    }
}
