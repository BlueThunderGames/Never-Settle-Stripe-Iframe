# Stripe Payment Trial

An embeddable invoice payment form, built to sit in an iframe on a Webflow site. A customer enters an invoice number, a payment amount and a payment method (card or ACH). A **processing fee** is calculated on the server and added to the total, so the client's net proceeds match the invoice amount after Stripe's standard rounded fee. Rates are assumed standard public pricing (2.9% + 30¢ card, 0.8% capped at $5 ACH), not a live quote from Stripe.

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
- [Production notes](#production-notes)
- [Known limitations](#known-limitations)
- [Verification status](#verification-status)
- [Decisions and trade-offs](#decisions-and-trade-offs)
- [What is not covered](#what-is-not-covered)

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

- Read `storage/logs/laravel.log` and search for an attempt ID to follow one payment. On macOS and Linux, `composer run logs` streams it live with Pail. Pail needs the `pcntl` PHP extension, which isn't available on Windows, so there use `Get-Content storage/logs/laravel.log -Wait -Tail 50` in PowerShell.
- `php artisan queue:failed` lists jobs that failed permanently, and `php artisan queue:retry all` re-runs them. A permanently failed attempt is also marked failed on its row in `payment_attempts`, and the form tells the customer to start again.

## Fees

Stripe takes its fee from the total we charge, not from the invoice amount, so the fee has to be calculated on the total:

```
card:  total = (invoice + 30¢) ÷ 0.971
ach:   total = invoice ÷ 0.992        (fee capped at $5.00)
```

That closed form is **rounded up to the next cent** (integer ceiling division). Stripe then rounds *its* fee (`round(total × 2.9%) + 30¢` for cards, `min(round(total × 0.8%), $5)` for ACH). A ceil-only total can sit one cent above that, so the form fee and the dashboard fee would not match.

After the ceil we **walk the total by a cent** until the merchant nets the invoice and the fee we add equals Stripe's rounded fee. The quote, the PaymentIntent, and the Stripe dashboard then agree.

| Method | Stripe's fee | What we add |
|---|---|---|
| Card | 2.9% + 30¢ | Fee grossed up so the invoice is covered |
| ACH | 0.8%, max $5.00 | Fee grossed up, capped at $5.00 |

| Invoice | Method | Total charged | Fee added |
|---|---|---|---|
| $100.00 | Card | $103.30 | $3.30 |
| $100.00 | ACH | $100.81 | $0.81 |
| $100.98 | Card | $104.30 | $3.32 |
| $100.98 | ACH | $101.79 | $0.81 |
| $0.50 | Card | $0.82 | $0.32 |
| $1,000.50 | Card | $1,030.69 | $30.19 |
| $1,000.50 | ACH | $1,005.50 | $5.00 |

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

Stripe now manages payment methods from the Dashboard, so also make sure **Cards** and **ACH Direct Debit** are turned on under Settings → Payment methods (in test mode). The app limits each payment to the method the customer chose, because the fee differs between them.

### Run it

```bash
composer run dev
```

This starts the web server, **the queue worker** and Vite together. Open <http://localhost:8000>.

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
5. Repeat with **ACH**. The Payment Element offers two ways to add a bank account, and the result page differs:
   - **Instant verification** (log in to a test bank, using Stripe's [test bank details](https://docs.stripe.com/testing)): the page says the payment was **submitted** and may take a few business days. Test-mode ACH payments settle on Stripe's schedule, so they can stay in `processing` for a while.
   - **Entering the account and routing number by hand**: Stripe has to verify the account with a small deposit first. The page says **Verify your bank account**, shows when the deposit arrives and links to Stripe's verification page. The payment isn't complete until the customer verifies, so it isn't a failure. In test mode, Stripe's testing page lists the test micro-deposit values to use.
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
| `PAYMENTS_MAX_TOTAL_CENTS` | `99999999` | Largest total charged, fee included (Stripe's per-payment limit). Requests that would exceed it are rejected |
| `SESSION_SAME_SITE`, `SESSION_SECURE_COOKIE`, `SESSION_PARTITIONED_COOKIE` | `lax`, unset, `false` | Cookie settings; see [Cookies inside the iframe](#cookies-inside-the-iframe) |

Rates, limits and embedding settings live in `config/payments.php`.

## Running the tests

There are two suites, and neither needs Stripe keys, a worker or the network.

```bash
composer test    # PHP: the backend
npm test         # JavaScript: the payment form's behaviour
```

**PHP tests** (`tests/Unit`, `tests/Feature`) use an in-memory database, run queued jobs inline and mock Stripe. They cover amount parsing, the fee calculation (including Stripe's rounded fee, rounding boundaries and the ACH cap), request validation, the idempotent creation of attempts, the Stripe service and job (retries, rejections and partial failures), the status and return pages, and the embedding headers.

**JavaScript tests** (`tests/js`) run [Vitest](https://vitest.dev) in a simulated browser with fake timers and a fake `fetch` and Stripe.js. They cover the debounced live quotes, stale responses being ignored, the idempotency key rules (reuse on a retry of the same details, a new key after a failure or a changed amount), polling and its timeout, mounting and confirming the payment, and the error messages. They use a copy of the form's markup, and `tests/Feature/PaymentFormMarkupTest.php` checks the real template still has every element the script depends on.

Both suites should pass before you commit. Code style is checked with `vendor/bin/pint --test`.

## API

All endpoints are JSON, protected by CSRF tokens (the form sends one automatically) and rate limited.

### `POST /payments/quote`

Prices an invoice amount for a payment method. Limited to 60 requests per minute.

```json
{ "amount": "1,000.50", "payment_type": "card" }
```

- `amount`: dollars as a string or number. `1,000.50`, `$12` and `19.99` are accepted. More than 2 decimals is rejected. The amount is also rejected if the invoice plus its fee would exceed Stripe's per-payment maximum.
- `payment_type`: `card` or `ach`.

Response `200`:

```json
{ "amount_cents": 100050, "fee_cents": 3019, "total_cents": 103069 }
```

### `POST /payments/attempts`

Saves a payment attempt and queues the Stripe work. Returns `202` without calling Stripe. Limited to 20 requests per minute.

Headers: `Idempotency-Key: <UUID>`, generated per payment attempt by the form.

```json
{ "invoice_number": "INV-1042", "amount": "100.98", "payment_type": "card" }
```

Response `202`:

```json
{ "id": "01k8…", "state": "pending", "amount_cents": 10098, "fee_cents": 332, "total_cents": 10430 }
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
| `app/Services/FeeCalculator.php` | Calculates the processing fee for card and ACH, aligned to Stripe's rounded fee |
| `app/Services/PaymentAttemptService.php` | Creates a payment attempt, or returns the existing one for the same idempotency key |
| `app/Services/StripePaymentService.php` | Creates the PaymentIntent and syncs its status, with the failure handling described above |
| `app/Jobs/CreateStripePaymentIntent.php` | The queued job: retries, backoff and logging |
| `app/Http/Controllers/` | One small controller per job: `PaymentFormController` (the page), `PaymentQuoteController`, `PaymentAttemptController` (create and poll) and `PaymentReturnController` |
| `app/Http/Requests/` | Validation for the quote and create-attempt endpoints |
| `app/Http/Middleware/AllowIframeEmbedding.php` | Sets the `frame-ancestors` header from `PAYMENTS_EMBED_ORIGINS` |
| `app/Rules/PaymentAmount.php` | Validation rule for the amount (format, minimum and maximum), shared by both requests |
| `app/Exceptions/` | `IdempotencyKeyReused` (a key reused with different details) and `PaymentProviderException` (a Stripe failure, flagged retryable or not) |
| `app/Models/PaymentAttempt.php` | One payment attempt and its Stripe status |
| `app/Enums/PaymentType.php` | The two payment options, `card` and `ach`, and the Stripe method type each maps to |
| `app/Enums/PaymentAttemptStatus.php` | Attempt statuses: Stripe's PaymentIntent statuses, plus `pending` (not created yet) and `failed` (we couldn't create it) |
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

## Production notes

This is set up to run locally. Before using it for real:

- **Database, sessions, cache and queue.** They all use SQLite files here, which is fine for a demo. Use MySQL or PostgreSQL for the database, and Redis or the database for the queue, cache and sessions. SQLite is configured with WAL mode and a busy timeout so the web app and the worker can share it, but it isn't meant for real traffic.
- **Run the worker under a process manager** such as Supervisor or systemd, with `php artisan queue:work --tries=5`, and restart it on each deploy (`php artisan queue:restart`). `queue:listen`, which `composer run dev` uses, is for development only.
- **HTTPS and cookies.** Serve the app over HTTPS and set the cookie options in [Cookies inside the iframe](#cookies-inside-the-iframe).
- **Live Stripe keys, and the Dashboard.** Use live keys, and enable Cards and ACH Direct Debit in the live Dashboard.
- **Clean-up.** Nothing prunes old payment attempts or `failed_jobs` rows. Add a scheduled prune (`php artisan queue:prune-failed --hours=720`, and your own policy for attempts) and decide how long to keep them.
- **Stale attempts.** With no webhooks, an attempt whose customer never returned stays out of date. A scheduled command that re-checks unfinished attempts, or adding webhooks, would close that gap.
- **Surcharge rules.** See [the decision above](#decision-card-surcharges-are-not-capped-by-default) before charging real customers.

## Known limitations

- **No webhooks.** Payment status is read from Stripe when the customer returns to the site. A customer who closes the tab before returning leaves the attempt out of date, and an ACH payment stays `processing` until it's checked again. The payment itself is still in Stripe with the invoice number in its metadata, so the client can reconcile it by hand.
- **No invoice system integration.** The invoice number is stored as plain text, and matching payments to invoices is done manually in Stripe.
- **Card surcharge rules.** See [the decision above](#decision-card-surcharges-are-not-capped-by-default). The fee is included in the total charged and isn't sent to Stripe as a separate surcharge, and debit cards aren't distinguished from credit cards.
- **Client secret.** The PaymentIntent's client secret is stored encrypted on the attempt so the form can fetch it, and it's cleared once the payment is final. Anyone holding an attempt's ID, a long random value, can fetch it, which is how Stripe's own client secrets work.
- **Fixed iframe height.** The embed doesn't resize itself to its content, so choose a height that fits the form and the payment fields.
- **Payment Element inside an iframe.** Stripe advises against placing the Payment Element inside another iframe, because some payment methods need to redirect the page. The form asks Stripe not to redirect unless it must (`redirect: 'if_required'`) and then moves only its own frame to the result page, which keeps the host page in place for card payments. Payment methods that must redirect are not enabled. If the embed proves unreliable on the real site, link customers to the payment page as a top-level page instead of embedding it.
- **Separate sessions and reloads.** Each browser session, and each page reload, starts a new payment attempt, and abandoned attempts are not cleaned up. The idempotency key lives only in the page's memory.

## Verification status

What has been checked, and how, so nothing here is overstated.

**Checked live against a Stripe sandbox**
- A card payment end to end: attempt saved, worker created the PaymentIntent, payment fields shown, card charged, return page recorded `succeeded`. The payment appears in the Stripe Dashboard with the `Invoice <number>` description and the invoice number, amount, fee and attempt ID in its metadata.
- Form fee vs Stripe dashboard fee for card and ACH, including $100.00, $100.98, $0.50 (card) and $1,000.50 (card and ACH cap). Charge, processing fee, and net match the table in [Fees](#fees).
- PaymentIntent creation for both card and ACH, each limited to the method that was quoted, for the correct total.
- ACH with manually entered bank details: the payment correctly waits for micro-deposit verification, and the return page shows "Verify your bank account" with the deposit date and Stripe's verification link instead of a failure.
- The iframe embedding rule in a real browser: an allowed origin can embed the page and a disallowed one is blocked.
- A full card payment made from inside an iframe on a different origin (a stand-in page on `localhost:8001` embedding the app on `localhost:8000`): quote, payment fields, payment and the result page all work, and the result page loads inside the iframe without replacing the host page.

**Checked by automated tests only (Stripe is mocked)**
- Retries and backoff, Stripe rejections, a save failure after Stripe succeeds, and idempotency conflicts.
- The return page for a successful card payment, a submitted or cleared ACH payment and a declined payment.
- The form's behaviour in a simulated browser: live quotes, polling and its timeout, and when the idempotency key is reused or replaced.

**Not verified yet**
- ACH with instant bank login, and an ACH payment completing after verification.
- A card decline on the real return page.
- The form running inside the real Webflow site over HTTPS, with the cross-site cookie settings. Only localhost on different ports was tried.
- Card 3-D Secure and ACH bank login or verification from inside an iframe. These can open pop-ups or redirects, which are the cases Stripe warns about when the Payment Element is nested in another iframe.
- The queue worker and SQLite under real concurrent load.
- A fresh clone set up from this README.

## Decisions and trade-offs

- **Fees cover Stripe's cost in full.** The brief asks for the client to receive the full invoice amount, so the card fee is not capped at the 3% US surcharge limit by default. This needs a compliance review before real use. See [the decision above](#decision-card-surcharges-are-not-capped-by-default).
- **Stripe calls run in a queued job.** The brief had no webhooks and manual reconciliation, so this adds some complexity beyond the minimum, in exchange for retries, a record saved before every Stripe call, and a web app that doesn't depend on Stripe's response time. The cost is a short wait while the form polls, and a worker that must be running. See [why Stripe requests run in a background worker](#why-stripe-requests-run-in-a-background-worker).
- **No webhooks, as the brief specified.** The return page reads the result from Stripe instead.

## What is not covered

- ACH returns and failures that happen days after submission, and the Stripe fees that can come with them. Without webhooks nothing reports them back to the app.
- A scheduled re-check of abandoned or stale attempts, and clean-up of old data.
- Refunds, disputes, receipts and notifications.
- Monitoring and alerting, for example if the worker stops.
- Automated tests that call the real Stripe API. The Stripe tests use mocks, so an API change needs a manual sandbox run to catch.
