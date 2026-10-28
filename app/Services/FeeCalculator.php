<?php

namespace App\Services;

use App\Enums\PaymentType;
use InvalidArgumentException;

class FeeCalculator
{
    /**
     * @param  array{card: array{percentage: float, fixed_cents: int, max_surcharge_percentage?: float|null}, ach: array{percentage: float, cap_cents: int}}  $fees
     */
    public function __construct(private array $fees) {}

    /**
     * Gross the invoice up so that, after Stripe takes its fee from the total,
     * the merchant still receives the full invoice amount.
     *
     * A string is accepted for convenience and must be a valid PaymentType value.
     *
     * @return array{amount_cents: int, fee_cents: int, total_cents: int}
     */
    public function quote(int $amountCents, PaymentType|string $paymentType): array
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        $type = $paymentType instanceof PaymentType
            ? $paymentType
            : PaymentType::tryFrom($paymentType) ?? throw new InvalidArgumentException("Unsupported payment type [{$paymentType}].");

        $feeCents = match ($type) {
            PaymentType::Card => $this->cardFee($amountCents),
            PaymentType::Ach => $this->achFee($amountCents),
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
        $total = $this->alignTotal($amountCents, $total, PaymentType::Card);
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
        $total = $amountCents + $this->ceilDiv($amountCents * $bps, 10000 - $bps);
        $total = $this->alignTotal($amountCents, $total, PaymentType::Ach);

        // Once Stripe's cap applies, it takes exactly the cap, so we add exactly the cap.
        return min($total - $amountCents, $this->fees['ach']['cap_cents']);
    }

    /**
     * The closed-form ceil can sit one cent above Stripe's rounded fee. Walk the total
     * so the merchant nets the invoice and the fee we add matches what Stripe will take.
     */
    private function alignTotal(int $amountCents, int $total, PaymentType $type): int
    {
        while ($total > $amountCents && $this->net($total - 1, $type) >= $amountCents) {
            $total--;
        }

        while ($this->net($total, $type) < $amountCents) {
            $total++;
        }

        return $total;
    }

    private function net(int $totalCents, PaymentType $type): int
    {
        return $totalCents - $this->stripeFee($totalCents, $type);
    }

    /**
     * Stripe's fee on a charge: round-half-up of the percentage, plus the card fixed fee,
     * ACH capped. Integer cents, same rounding Stripe uses on the dashboard.
     */
    private function stripeFee(int $totalCents, PaymentType $type): int
    {
        return match ($type) {
            PaymentType::Card => $this->roundPercent($totalCents, $this->fees['card']['percentage'])
                + $this->fees['card']['fixed_cents'],
            PaymentType::Ach => min(
                $this->roundPercent($totalCents, $this->fees['ach']['percentage']),
                $this->fees['ach']['cap_cents'],
            ),
        };
    }

    private function roundPercent(int $cents, float $percentage): int
    {
        $bps = $this->basisPoints($percentage);

        return intdiv($cents * $bps + 5000, 10000);
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
