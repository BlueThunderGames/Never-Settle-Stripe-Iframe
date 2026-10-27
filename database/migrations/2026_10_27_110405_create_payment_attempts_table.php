<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();

            // Opaque public identifier used in URLs (return_url) instead of the sequential id.
            $table->ulid('public_id')->unique();

            // Client-supplied key. A retried/double-submitted request maps to the same row.
            $table->string('idempotency_key', 255)->unique();
            // Hash of invoice/amount/type, to reject a reused key carrying different data.
            $table->char('request_fingerprint', 64);

            $table->string('invoice_number', 64)->index();
            $table->string('payment_type', 16);

            // Integer cents. total = amount + fee, and total is what Stripe charges.
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('fee_cents');
            $table->unsignedBigInteger('total_cents');
            $table->char('currency', 3)->default('usd');

            $table->string('status', 32)->default('pending')->index();
            $table->string('stripe_payment_intent_id')->nullable()->unique();

            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();

            // No webhook, so status is pulled from Stripe on demand; track when we last did.
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
