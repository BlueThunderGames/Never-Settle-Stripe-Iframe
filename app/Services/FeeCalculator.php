<?php

namespace App\Services;

use InvalidArgumentException;

class FeeCalculator
{
    public const CARD = 'card';

    public const ACH = 'ach';

    /**
     * @param  array{card: array{percentage: float, fixed_cents: int, max_surcharge_percentage?: float|null}, ach: array{percentage: float, cap_cents: int}}  $fees
     */
    public function __construct(private array $fees) {}

    /**
     * Gross the invoice up so that, after Stripe takes its fee from the total,
     * the merchant still receives the full invoice amount.
     *
     * @return array{amount_cents: int, fee_cents: int, total_cents: int}
     */
    public function quote(int $amountCents, string $paymentType): array
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        $feeCents = match ($paymentType) {
            self::CARD => $this->cardFee($amountCents),
            self::ACH => $this->achFee($amountCents),
            default => throw new InvalidArgumentException("Unsupported payment type [{$paymentType}]."),
        };

        return [
            'amount_cents' => $amountCents,
            'fee_cents' => $feeCents,
            'total_cents' => $amountCents + $feeCents,
        ];
    }

    private function cardFee(int $amountCents): int
    {
        $bps = $this->basisPoints($this->fees['card']['percentage']);
        $fixed = $this->fees['card']['fixed_cents'];

        // total - (total * rate + fixed) = amount  =>  total = (amount + fixed) / (1 - rate)
        $total = $this->ceilDiv(($amountCents + $fixed) * 10000, 10000 - $bps);
        $fee = $total - $amountCents;

        // Optional ceiling on the surcharge as a share of the invoice; rounded down so it's never exceeded.
        $maxPercentage = $this->fees['card']['max_surcharge_percentage'] ?? null;

        if ($maxPercentage !== null) {
            $fee = min($fee, intdiv($amountCents * $this->basisPoints($maxPercentage), 10000));
        }

        return $fee;
    }

    private function achFee(int $amountCents): int
    {
        $bps = $this->basisPoints($this->fees['ach']['percentage']);

        // total - total * rate = amount  =>  fee = amount * rate / (1 - rate)
        $fee = $this->ceilDiv($amountCents * $bps, 10000 - $bps);

        // Once Stripe's cap applies, it takes exactly the cap, so we add exactly the cap.
        return min($fee, $this->fees['ach']['cap_cents']);
    }

    // Percentages become integer basis points (2.9% => 290) to keep the math float-free.
    private function basisPoints(float $percentage): int
    {
        return (int) round($percentage * 100);
    }

    private function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
