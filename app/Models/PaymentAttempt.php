<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'amount_cents' => 'integer',
            'fee_cents' => 'integer',
            'total_cents' => 'integer',
            'last_synced_at' => 'datetime',
            'succeeded_at' => 'datetime',
        ];
    }

    // Only public_id is a ULID; the auto-increment id stays the primary key.
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Fingerprint of the user-supplied inputs that define this attempt.
     */
    public static function fingerprint(string $invoiceNumber, int $amountCents, string $paymentType): string
    {
        return hash('sha256', implode('|', [$invoiceNumber, $amountCents, $paymentType]));
    }
}
