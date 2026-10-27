# Stripe Payment Trial

An embeddable invoice payment form, built to sit in an iframe on a Webflow site. A customer enters an invoice number, a payment amount and a payment method (card or ACH). A **processing fee** is calculated on the server and added to the total, so the client receives the full invoice amount after Stripe takes its cut.

> **Status: proof of concept.** The form, fee calculation, quote API and payment attempt tracking are built. Creating the Stripe PaymentIntent, the return/confirmation page and iframe embedding headers are still to come (see [Status](#status)).

## Contents

- [How it works](#how-it-works)
- [Fees](#fees)
- [Getting started](#getting-started)
- [Configuration](#configuration)
- [Running the tests](#running-the-tests)
- [API](#api)
- [Project structure](#project-structure)
- [Status](#status)
- [Known limitations](#known-limitations)

## How it works

1. The customer enters an invoice number and amount and picks card or ACH.
2. Each time the amount or method changes, the form asks the server for a quote. The server validates the input, calculates the fee and returns the amount, fee and total, which the form displays.
3. The customer continues to payment, which creates a payment attempt on the server and mounts Stripe's Payment Element.
4. The customer pays, and sees a confirmation suited to the method they chose.

All money is handled as **integer cents**, never floats. This avoids rounding errors, and cents are also what the Stripe API expects (`amount=1099` is $10.99). The server always works out the price itself. It never trusts a total sent from the browser.

## Fees

Stripe takes its fee from the total we charge, not from the invoice amount, so the fee has to be calculated on the total:

```
card:  total = (invoice + 30¢) ÷ 0.971
ach:   total = invoice ÷ 0.992        (fee capped at $5.00)
```

The total is **rounded up to the next cent** using integer ceiling division, so we never round down and come up a fraction of a cent short.

| Method | Stripe's fee | What we add |
|---|---|---|
| Card | 2.9% + 30¢ | Fee grossed up so the invoice is covered |
| ACH | 0.8%, max $5.00 | Fee grossed up, capped at $5.00 |

| Invoice | Method | Total charged | Fee added |
|---|---|---|---|
| $100.98 | Card | $104.31 | $3.33 |
| $100.98 | ACH | $101.80 | $0.82 |

Stripe rounds its own fee to whole cents, so the merchant's net can differ from the invoice by a cent. The round-up is deliberately conservative, so it never lands short.

### Decision: card surcharges are not capped by default

Stripe's [surcharging guide](https://docs.stripe.com/payments/cards/surcharge) says US card surcharges are limited to **3%** (credit cards only), and card network rules and state laws apply on top. Fully covering a 2.9% + 30¢ fee takes more than 3% of the invoice below about **$2,308**, because the fixed 30¢ is a large share of a small invoice (about 6.1% on a $10 invoice). Capping at 3% would leave the merchant absorbing the difference, up to about 31¢ per card payment.

The goal of this trial is for the client to break even, so the full cost-recovery fee is calculated and the 3% limit is **not enforced by default**. This is an estimate for the demo, not confirmation that the fee can legally be charged. To enforce the limit and accept the shortfall instead, set `PAYMENTS_CARD_MAX_SURCHARGE_PERCENT=3.0`. ACH is not affected. **Get the surcharge rules reviewed before using this in production.**

## Getting started

### Requirements

- PHP 8.2 or newer (with the `sqlite3` and `mbstring` extensions)
- [Composer](https://getcomposer.org)
- Node.js 20.19 or newer (or 22.12+), with npm
- A [Stripe](https://dashboard.stripe.com/register) account (test mode is fine)

### Setup

```bash
git clone <repository-url>
cd stripe-payment-trial

composer run setup
```

`composer run setup` installs the PHP and JavaScript dependencies, creates your `.env` file, generates the app key, creates the SQLite database and runs the migrations, and builds the front-end assets.

Then add your Stripe **test** keys to `.env` (from the Stripe dashboard under Developers → API keys):

```env
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
```

### Run it

```bash
composer run dev
```

This starts the web server, queue worker, log viewer and Vite together. Open <http://localhost:8000>.

If you only want the app without the extra processes, run `php artisan serve` and `npm run dev` in two terminals.

## Configuration

Everything is set in `.env`. The defaults match the rates above, so you only need to set these to change them.

| Variable | Default | Purpose |
|---|---|---|
| `STRIPE_KEY` | | Stripe publishable key (`pk_…`). Safe to expose in the browser. |
| `STRIPE_SECRET` | | Stripe secret key (`sk_…`). Server-side only; never commit it. |
| `PAYMENTS_CARD_FEE_PERCENT` | `2.9` | Card percentage rate |
| `PAYMENTS_CARD_FEE_FIXED_CENTS` | `30` | Card fixed fee, in cents |
| `PAYMENTS_CARD_MAX_SURCHARGE_PERCENT` | *(unset)* | Optional ceiling on the card fee as a % of the invoice, for example `3.0` |
| `PAYMENTS_ACH_FEE_PERCENT` | `0.8` | ACH percentage rate |
| `PAYMENTS_ACH_FEE_CAP_CENTS` | `500` | ACH fee cap, in cents |
| `PAYMENTS_MIN_AMOUNT_CENTS` | `50` | Smallest invoice accepted ($0.50, Stripe's minimum) |
| `PAYMENTS_MAX_AMOUNT_CENTS` | `99999999` | Largest invoice accepted ($999,999.99) |

Rates and limits live in `config/payments.php`.

## Running the tests

```bash
composer test
```

The tests use an in-memory database and don't call Stripe. They cover amount parsing, the fee calculation (including rounding boundaries and the ACH cap), the quote endpoint's validation and responses, and the idempotent creation of payment attempts.

## API

### `POST /payments/quote`

Prices an invoice amount for a payment method. Requests must be JSON and include a CSRF token (the form sends one automatically). Limited to 60 requests per minute.

Request:

```json
{ "amount": "1,000.50", "payment_type": "card" }
```

- `amount`: dollars as a string or number. `1,000.50`, `$12` and `19.99` are accepted. More than 2 decimals is rejected.
- `payment_type`: `card` or `ach`.

Response `200`:

```json
{ "amount_cents": 100050, "fee_cents": 3020, "total_cents": 103070 }
```

Invalid input returns `422` with the standard validation error format:

```json
{
  "message": "The minimum payment is $0.50.",
  "errors": { "amount": ["The minimum payment is $0.50."] }
}
```

## Project structure

| Path | What it does |
|---|---|
| `app/Services/MoneyParser.php` | Turns user-entered dollars into integer cents |
| `app/Services/FeeCalculator.php` | Calculates the processing fee for card and ACH |
| `app/Services/PaymentAttemptService.php` | Creates a payment attempt, or returns the existing one for the same idempotency key |
| `app/Services/StripePaymentService.php` | Stripe PaymentIntent handling (not built yet) |
| `app/Http/Requests/PaymentQuoteRequest.php` | Validation for the quote endpoint |
| `app/Http/Controllers/PaymentController.php` | Form page and quote endpoint |
| `app/Models/PaymentAttempt.php` | A single payment attempt and its Stripe status |
| `app/Enums/PaymentAttemptStatus.php` | Attempt statuses, mirroring Stripe's PaymentIntent statuses |
| `config/payments.php` | Fee rates, limits and currency |
| `resources/views/payments/form.blade.php` | The payment form |
| `resources/js/payment-form.js` | Live quoting and the two-step payment flow |

### Idempotency

Every payment attempt has a unique idempotency key. Retrying or double-submitting with the same key returns the same attempt instead of creating a second one. Reusing a key with a different invoice number, amount or payment method is rejected. A retry keeps its original pricing even if the rates change in the meantime.

## Status

**Built**

- Payment form and iframe layout
- Live fee quotes from the server, with client-side and server-side validation
- Fee calculation for card and ACH
- Payment attempt model, with idempotent creation

**Still to do**

- Create the Stripe PaymentIntent for an attempt (invoice number, amount and fee in its metadata and description, so payments can be matched to invoices by hand in Stripe)
- The `/payments/attempts` endpoint the form calls to start payment
- The return page. A card shows paid or failed. ACH shows "submitted, may take a few business days", because bank payments don't settle instantly.
- Headers that allow the page to be embedded in the Webflow site, and cookie settings that work inside an iframe

## Known limitations

- **No webhooks.** Payment status is read from Stripe when the customer returns to the site. A customer who closes the tab first leaves the attempt stale, and an ACH payment stays `processing` until it's checked again.
- **No invoice system integration.** The invoice number is stored as plain text. Matching payments to invoices is done manually in Stripe.
- **Card surcharge rules.** See [the decision above](#decision-card-surcharges-are-not-capped-by-default). The fee is currently included in the total charged and isn't sent to Stripe as a separate surcharge, and debit cards aren't distinguished from credit cards.
