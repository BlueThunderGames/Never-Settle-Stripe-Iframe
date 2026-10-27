# Stripe Payment Trial

An embeddable invoice payment form, built to sit in an iframe on a Webflow site. A customer enters an invoice number, a payment amount and a payment method (card or ACH). A **processing fee** is calculated on the server and added to the total, so the client receives the full invoice amount after Stripe takes its cut.

> **Proof of concept.** The flow is built end to end: form, fee quotes, background payment creation, confirmation page and iframe embedding. The Stripe calls are tested against mocks; do a test payment with your own sandbox keys (see [Try a payment](#try-a-payment)) before relying on it.

## Contents

- [How it works](#how-it-works)
- [Why Stripe requests run in a background worker](#why-stripe-requests-run-in-a-background-worker)
- [Fees](#fees)
- [Getting started](#getting-started)
- [Try a payment](#try-a-payment)
- [Embedding in Webflow](#embedding-in-webflow)
- [Configuration](#configuration)
- [Running the tests](#running-the-tests)
- [API](#api)
- [Project structure](#project-structure)
- [Known limitations](#known-limitations)

## How it works

```
Browser                         Web app                          Worker                    Stripe
   |  POST /payments/quote  ->     |  prices the amount              |                         |
   |  <- amount, fee, total        |                                 |                         |
   |                               |                                 |                         |
   |  POST /payments/attempts ->   |  saves a payment attempt,       |                         |
   |  (Idempotency-Key header)     |  queues a job, replies 202      |                         |
   |  <- attempt id                |                                 |                         |
   |                               |        job picked up ---------> |  create PaymentIntent ->|
   |  GET /payments/attempts/{id}  |                                 |  <- intent + secret     |
   |  (polls every ~1s)  ->        |  <---- saves intent id + client secret                    |
   |  <- ready + client secret     |                                 |                         |
   |                               |                                 |                         |
   |  customer pays with Stripe's Payment Element ----------------------------------------->  |
   |  redirected to /payments/return/{id} -> asks Stripe for the status -> shows the result    |
```

1. The customer enters an invoice number and amount and picks card or ACH. Each time the amount or method changes, the form asks the server for a quote and shows the amount, fee and total.
2. On Continue, the server validates the request and **saves a payment attempt** (priced on the server, never from a total sent by the browser). It queues a background job and replies right away.
3. A **queue worker** creates the Stripe PaymentIntent. The form polls until the intent is ready, then shows Stripe's Payment Element.
4. After paying, Stripe redirects to the return page, which fetches the final status from Stripe and shows a success or error message suited to the payment method. A card shows paid or failed. ACH shows "submitted, may take a few business days", because bank payments don't clear instantly.

All money is handled as **integer cents**, never floats. This avoids rounding errors, and cents are what the Stripe API expects (`amount=1099` is $10.99).

## Why Stripe requests run in a background worker

Creating the PaymentIntent is the one step that depends on an outside service, so it runs as a queued job (`app/Jobs/CreateStripePaymentIntent.php`) instead of inside the web request.

- **The record comes first.** The attempt is saved before Stripe is called, so a Stripe request never exists without a database record tying it back to an invoice.
- **Retries with backoff.** If Stripe can't be reached, the job retries up to 5 times, waiting 2, 5, 15 and 45 seconds. A web request would just have to fail. A rejection from Stripe (a bad request, for example) is not retried, because it can't succeed.
- **Safe to repeat.** Every Stripe call uses an idempotency key derived from the attempt, and only one job per attempt runs at a time. A retried or duplicated job gets the same PaymentIntent back and never creates a second one.
- **Nothing is lost on a crash.** If Stripe succeeds but the database save fails, the retry reconnects to the same intent. The intent also carries our attempt ID in its metadata.
- **Traceable.** Every log line from the job includes the attempt ID, job ID and try number.
- **The web app stays fast.** The browser gets a reply immediately, and a slow or failing Stripe doesn't tie up web requests.

The cost is a short wait (usually a second or two) before the payment fields appear, while the form polls.

### A worker must be running

**If no worker is running, payments won't start.** The job sits in the `jobs` table, the form waits about 75 seconds and then shows a "taking longer than expected" message. The jobs run as soon as a worker starts.

- `composer run dev` already starts a worker along with everything else.
- Without it, run one yourself in a separate terminal:

  ```bash
  php artisan queue:listen --tries=1 --timeout=0   # picks up code changes automatically
  # or
  php artisan queue:work                           # faster; restart it after code changes
  ```

The queue uses the database (`QUEUE_CONNECTION=database`), so there's nothing extra to install. The tests run jobs inline and don't need a worker.

**Debugging**

- `php artisan pail` streams the log live, or read `storage/logs/laravel.log`. Search for an attempt ID to follow one payment.
- `php artisan queue:failed` lists jobs that failed permanently, and `php artisan queue:retry all` re-runs them. A permanently failed attempt is also marked failed on its row in `payment_attempts`, and the form tells the customer to start again.

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

`composer run setup` installs the PHP and JavaScript dependencies, creates your `.env` file, generates the app key, creates the SQLite database and runs the migrations (including the queue tables), and builds the front-end assets.

Then add your Stripe **test** keys to `.env` (from the Stripe dashboard under Developers → API keys):

```env
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
```

### Run it

```bash
composer run dev
```

This starts the web server, **the queue worker**, a log viewer and Vite together. Open <http://localhost:8000>.

To run the pieces yourself instead, use three terminals:

```bash
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
npm run dev
```

## Try a payment

1. Open <http://localhost:8000>, enter an invoice number and an amount, and choose **card**.
2. Continue. The payment fields appear after a moment, once the worker has created the PaymentIntent. If they don't, check that the worker is running.
3. Pay with one of Stripe's [test cards](https://docs.stripe.com/testing), for example `4242 4242 4242 4242` with any future expiry and any CVC. You should land on a "Payment successful" page.
4. Try a declining card from the same list to see the error page.
5. Repeat with **ACH**, using Stripe's [test bank account details](https://docs.stripe.com/testing). The result page should say the payment was submitted and may take a few business days. Test-mode ACH payments settle on Stripe's schedule, so they can remain in `processing` for a while.
6. Open the payment in the Stripe dashboard (test mode). The description is `Invoice <number>`, and the metadata holds the invoice number, invoice amount, processing fee and payment attempt ID, which is how the client matches payments to invoices by hand.

## Embedding in Webflow

The page can only be embedded by sites listed in `PAYMENTS_EMBED_ORIGINS`, using the `Content-Security-Policy: frame-ancestors` header. Any other site is blocked by the browser. The app's own origin is always allowed.

```env
# Comma separated. localhost on any port is included for local testing.
PAYMENTS_EMBED_ORIGINS=http://localhost:*,http://127.0.0.1:*,https://*.webflow.io
```

To swap in the real Webflow site, change the value and add its address, for example:

```env
PAYMENTS_EMBED_ORIGINS=https://www.example.com,https://example.webflow.io,http://localhost:*
```

Entries are host sources: `https://example.com`, a leading `*.` for any subdomain, and `:*` for any port. Anything malformed is ignored, so a bad value can't change the rest of the policy. After changing `.env`, restart the server (and run `php artisan config:clear` if you've cached config).

Then add a Webflow **Embed** element with an iframe pointing at the deployed app:

```html
<iframe
  src="https://your-app.example.com/"
  title="Pay an invoice"
  style="width: 100%; min-height: 680px; border: 0;"
  loading="lazy"
></iframe>
```

### Cookies inside the iframe

When the app and the Webflow site are on **different sites**, browsers treat the app's session cookie as a third-party cookie and may block it. The form needs it for CSRF protection, so set this in the deployed `.env` (HTTPS is required):

```env
SESSION_SAME_SITE=none
SESSION_SECURE_COOKIE=true
SESSION_PARTITIONED_COOKIE=true
```

Locally you don't need this, since localhost on different ports counts as the same site.

## Configuration

Everything is set in `.env`. The defaults match the rates above, so you only need to set these to change them.

| Variable | Default | Purpose |
|---|---|---|
| `STRIPE_KEY` | | Stripe publishable key (`pk_…`). Safe to expose in the browser. |
| `STRIPE_SECRET` | | Stripe secret key (`sk_…`). Server-side only; never commit it. |
| `QUEUE_CONNECTION` | `database` | Where jobs are queued. A worker must be running. |
| `PAYMENTS_EMBED_ORIGINS` | localhost, `https://*.webflow.io` | Sites allowed to embed the form in an iframe |
| `PAYMENTS_CARD_FEE_PERCENT` | `2.9` | Card percentage rate |
| `PAYMENTS_CARD_FEE_FIXED_CENTS` | `30` | Card fixed fee, in cents |
| `PAYMENTS_CARD_MAX_SURCHARGE_PERCENT` | *(unset)* | Optional ceiling on the card fee as a % of the invoice, for example `3.0` |
| `PAYMENTS_ACH_FEE_PERCENT` | `0.8` | ACH percentage rate |
| `PAYMENTS_ACH_FEE_CAP_CENTS` | `500` | ACH fee cap, in cents |
| `PAYMENTS_MIN_AMOUNT_CENTS` | `50` | Smallest invoice accepted ($0.50, Stripe's minimum) |
| `PAYMENTS_MAX_AMOUNT_CENTS` | `99999999` | Largest invoice accepted ($999,999.99) |
| `SESSION_SAME_SITE`, `SESSION_SECURE_COOKIE`, `SESSION_PARTITIONED_COOKIE` | `lax`, unset, `false` | Cookie settings; see [Cookies inside the iframe](#cookies-inside-the-iframe) |

Rates, limits and embedding settings live in `config/payments.php`.

## Running the tests

```bash
composer test
```

The tests use an in-memory database, run queued jobs inline and mock Stripe, so they need no keys, no worker and no network. They cover amount parsing, the fee calculation (including rounding boundaries and the ACH cap), request validation, the idempotent creation of attempts, the Stripe service and job (retries, rejections and partial failures), the status and return pages, and the embedding headers.

## API

All endpoints are JSON, protected by CSRF tokens (the form sends one automatically) and rate limited.

### `POST /payments/quote`

Prices an invoice amount for a payment method. Limited to 60 requests per minute.

```json
{ "amount": "1,000.50", "payment_type": "card" }
```

- `amount`: dollars as a string or number. `1,000.50`, `$12` and `19.99` are accepted. More than 2 decimals is rejected.
- `payment_type`: `card` or `ach`.

Response `200`:

```json
{ "amount_cents": 100050, "fee_cents": 3020, "total_cents": 103070 }
```

### `POST /payments/attempts`

Saves a payment attempt and queues the Stripe work. Returns `202` without calling Stripe. Limited to 20 requests per minute.

Headers: `Idempotency-Key: <UUID>`, generated per payment attempt by the form.

```json
{ "invoice_number": "INV-1042", "amount": "100.98", "payment_type": "card" }
```

Response `202`:

```json
{ "id": "01k8…", "state": "pending", "amount_cents": 10098, "fee_cents": 333, "total_cents": 10431 }
```

- The same key with the same details returns the same attempt, so retries and double clicks never create a second payment.
- The same key with **different** details returns `409`.
- Invalid input returns `422` in the standard Laravel format, with errors keyed by field (`invoice_number`, `amount`, `payment_type`, `idempotency_key`).

### `GET /payments/attempts/{id}`

What the form polls. Limited to 120 requests per minute. The response is never cached.

| `state` | Meaning |
|---|---|
| `pending` | The worker hasn't created the PaymentIntent yet |
| `ready` | Includes `client_secret`; the form shows the Payment Element |
| `failed` | Includes a generic `message`; the customer must start again |
| `done` | Already submitted or finished; no further input is possible |

### `GET /payments/return/{id}`

The page Stripe redirects to after payment. It refreshes the status from Stripe and renders a success, processing or error message.

## Project structure

| Path | What it does |
|---|---|
| `app/Services/MoneyParser.php` | Turns user-entered dollars into integer cents |
| `app/Services/FeeCalculator.php` | Calculates the processing fee for card and ACH |
| `app/Services/PaymentAttemptService.php` | Creates a payment attempt, or returns the existing one for the same idempotency key |
| `app/Services/StripePaymentService.php` | Creates the PaymentIntent and syncs its status, with the failure handling described above |
| `app/Jobs/CreateStripePaymentIntent.php` | The queued job: retries, backoff and logging |
| `app/Http/Controllers/PaymentController.php` | Form page, quote, create attempt, status and return page |
| `app/Http/Requests/` | Validation for the quote and create-attempt endpoints |
| `app/Http/Middleware/AllowIframeEmbedding.php` | Sets the `frame-ancestors` header from `PAYMENTS_EMBED_ORIGINS` |
| `app/Models/PaymentAttempt.php` | One payment attempt and its Stripe status |
| `app/Enums/PaymentAttemptStatus.php` | Attempt statuses, mirroring Stripe's PaymentIntent statuses |
| `config/payments.php` | Fee rates, limits, currency and embedding settings |
| `resources/views/payments/` | The payment form and the return page |
| `resources/js/payment-form.js` | Live quoting, polling for the PaymentIntent and the two-step payment flow |

### Idempotency

Every payment attempt has a unique idempotency key. Retrying or double-submitting with the same key returns the same attempt instead of creating a second one. Reusing a key with a different invoice number, amount or payment method is rejected. A retry keeps its original pricing even if the rates change in the meantime. If Stripe rejects an attempt, that attempt is finished (Stripe replays the stored result for a reused key), so the form starts a fresh attempt with a new key.

### What happens when something fails

| Failure | Result |
|---|---|
| Saving the attempt fails | Stripe is never called, so nothing can be left disconnected |
| Stripe can't be reached | The job retries with backoff. The same idempotency key means no duplicate PaymentIntent |
| Stripe rejects the request | The reason is saved on the attempt and logged. The customer is asked to start again |
| Stripe succeeds but saving the result fails | The job retries, gets the same intent back and saves it. The intent's metadata also holds the attempt ID |
| All retries are used up | The attempt is marked failed and the form shows an error |
| Stripe is unreachable on the return page | The page shows the last known status |

## Known limitations

- **No webhooks.** Payment status is read from Stripe when the customer returns to the site. A customer who closes the tab before returning leaves the attempt out of date, and an ACH payment stays `processing` until it's checked again. The payment itself is still in Stripe with the invoice number in its metadata, so the client can reconcile it by hand.
- **No invoice system integration.** The invoice number is stored as plain text, and matching payments to invoices is done manually in Stripe.
- **Card surcharge rules.** See [the decision above](#decision-card-surcharges-are-not-capped-by-default). The fee is included in the total charged and isn't sent to Stripe as a separate surcharge, and debit cards aren't distinguished from credit cards.
- **Client secret.** The PaymentIntent's client secret is stored encrypted on the attempt so the form can fetch it, and it's cleared once the payment is final. Anyone holding an attempt's ID, a long random value, can fetch it, which is how Stripe's own client secrets work.
- **Fixed iframe height.** The embed doesn't resize itself to its content, so choose a height that fits the form and the payment fields.
