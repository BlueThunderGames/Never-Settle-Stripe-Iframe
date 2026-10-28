// Behaviour tests for resources/js/payment-form.js, run in jsdom with fake timers.
// The HTML below mirrors the data-* hooks in resources/views/payments/form.blade.php;
// tests/Feature/PaymentFormMarkupTest.php checks the real template still has every hook.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const FORM_HTML = `
<form id="payment-form" data-payment-form
    data-stripe-key="pk_test_123"
    data-quote-url="/payments/quote"
    data-intent-url="/payments/attempts"
    data-return-url="/payments/return">
    <div role="alert" data-form-error class="hidden"></div>
    <fieldset data-step="details">
        <input id="invoice_number" name="invoice_number">
        <p data-field-error="invoice_number"></p>
        <input id="amount" name="amount">
        <p data-field-error="amount"></p>
        <input type="radio" name="payment_type" value="card" data-payment-type checked>
        <input type="radio" name="payment_type" value="ach" data-payment-type>
        <p data-field-error="payment_type"></p>
    </fieldset>
    <dl>
        <dd data-summary-amount>—</dd>
        <dd data-summary-fee>—</dd>
        <dd data-summary-total>—</dd>
    </dl>
    <div data-step="payment" class="hidden">
        <button type="button" data-edit-details>Edit invoice details</button>
        <div id="payment-element" data-payment-element></div>
    </div>
    <button type="submit" data-submit>
        <svg data-submit-spinner class="hidden"></svg>
        <span data-submit-label>Continue to payment</span>
    </button>
</form>`;

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

const deferred = () => {
    let resolve;
    const promise = new Promise((r) => (resolve = r));
    return { promise, resolve };
};

let server;
let stripe;

function makeServer() {
    const state = {
        calls: [],
        polls: [{ state: 'ready', client_secret: 'pi_1_secret', amount_cents: 10098, fee_cents: 332, total_cents: 10430 }],
        pollIndex: 0,
        create: () => ({ status: 202, body: { id: 'att-1', state: 'pending' } }),
        quote: (payload) => {
            const amount = Math.round(parseFloat(payload.amount.replace(/,/g, '')) * 100);
            const fee = payload.payment_type === 'card' ? 300 : 82;

            return { status: 200, body: { amount_cents: amount, fee_cents: fee, total_cents: amount + fee } };
        },
        async route(call) {
            if (call.url === '/payments/quote') return this.quote(call.body);
            if (call.url === '/payments/attempts') return this.create(call);

            const poll = this.polls[Math.min(this.pollIndex++, this.polls.length - 1)];

            return { status: 200, body: poll };
        },
        post(url) {
            return this.calls.filter((c) => c.method === 'POST' && c.url === url);
        },
        gets() {
            return this.calls.filter((c) => c.method === 'GET');
        },
    };

    vi.stubGlobal('fetch', vi.fn(async (url, options = {}) => {
        const call = {
            url,
            method: options.method ?? 'GET',
            headers: options.headers ?? {},
            body: options.body ? JSON.parse(options.body) : null,
        };
        state.calls.push(call);
        const result = await state.route(call);

        return { ok: result.status < 400, status: result.status, json: async () => result.body };
    }));

    return state;
}

function makeStripe() {
    const mount = vi.fn((el) => (el.textContent = '[payment element]'));
    const create = vi.fn(() => ({ mount }));
    const elements = vi.fn(() => ({ create }));
    const confirmPayment = vi.fn(async () => ({}));
    const Stripe = vi.fn(() => ({ elements, confirmPayment }));

    window.Stripe = Stripe;

    return { Stripe, elements, create, mount, confirmPayment };
}

const settle = async (ms = 0) => {
    await vi.advanceTimersByTimeAsync(ms);
    await vi.advanceTimersByTimeAsync(0);
};

const $ = (selector) => document.querySelector(selector);
const text = (selector) => $(selector).textContent.trim();
const isHidden = (selector) => $(selector).classList.contains('hidden');

async function mountForm() {
    document.head.innerHTML = '<meta name="csrf-token" content="csrf-123">';
    document.body.innerHTML = FORM_HTML;
    vi.resetModules();
    await import('../../resources/js/payment-form.js');
}

function type(selector, value) {
    const input = $(selector);
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

function choose(value) {
    const radio = document.querySelector(`[data-payment-type][value="${value}"]`);
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
}

function submit() {
    $('#payment-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

async function fillValidDetails(invoice = 'INV-1042', amount = '100.98') {
    type('#invoice_number', invoice);
    type('#amount', amount);
    await settle(400);
}

// Goes from the details step to the payment step with a ready PaymentIntent.
async function reachPaymentStep() {
    await fillValidDetails();
    submit();
    await settle(5000);
}

beforeEach(async () => {
    vi.useFakeTimers();
    server = makeServer();
    stripe = makeStripe();
    await mountForm();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    delete window.Stripe;
});

describe('live quotes', () => {
    it('starts with dashes and the card label', () => {
        expect(text('[data-summary-total]')).toBe('—');
        expect(text('[data-submit-label]')).toBe('Continue to card payment');
    });

    it('waits for typing to pause, then asks the server and shows amount, fee and total', async () => {
        type('#amount', '100.98');
        await settle(200);
        expect(server.post('/payments/quote')).toHaveLength(0);
        expect(text('[data-summary-fee]')).toBe('…');

        await settle(200);

        const [call] = server.post('/payments/quote');
        expect(call.body).toEqual({ amount: '100.98', payment_type: 'card' });
        expect(call.headers['X-CSRF-TOKEN']).toBe('csrf-123');
        expect(text('[data-summary-amount]')).toBe('$100.98');
        expect(text('[data-summary-fee]')).toBe('$3.00');
        expect(text('[data-summary-total]')).toBe('$103.98');
    });

    it('sends only one request when the amount changes quickly', async () => {
        type('#amount', '1');
        await settle(100);
        type('#amount', '12');
        await settle(100);
        type('#amount', '123');
        await settle(400);

        expect(server.post('/payments/quote')).toHaveLength(1);
        expect(server.post('/payments/quote')[0].body.amount).toBe('123');
    });

    it('re-quotes immediately when the payment method changes and relabels the button', async () => {
        type('#amount', '100.98');
        await settle(400);

        choose('ach');
        await settle(0);

        expect(server.post('/payments/quote').at(-1).body.payment_type).toBe('ach');
        expect(text('[data-summary-fee]')).toBe('$0.82');
        expect(text('[data-submit-label]')).toBe('Continue to bank account');
    });

    it('ignores a slow response that arrives after a newer one', async () => {
        const slow = deferred();
        const original = server.route.bind(server);
        server.route = async (call) => (call.url === '/payments/quote' && call.body.amount === '10' ? slow.promise : original(call));

        type('#amount', '10');
        await settle(400);
        type('#amount', '20');
        await settle(400);
        expect(text('[data-summary-amount]')).toBe('$20.00');

        slow.resolve({ status: 200, body: { amount_cents: 1000, fee_cents: 300, total_cents: 1300 } });
        await settle(0);

        expect(text('[data-summary-amount]')).toBe('$20.00');
    });

    it('does not call the server for an invalid amount', async () => {
        type('#amount', 'abc');
        await settle(500);

        expect(server.post('/payments/quote')).toHaveLength(0);
        expect(text('[data-field-error="amount"]')).toBe('Enter a valid dollar amount, e.g. 125.50');
        expect(text('[data-summary-total]')).toBe('—');
    });

    it('shows the server validation message when a quote is rejected', async () => {
        server.quote = () => ({ status: 422, body: { message: 'x', errors: { amount: ['The minimum payment is $0.50.'] } } });

        type('#amount', '0.10');
        await settle(400);

        expect(text('[data-field-error="amount"]')).toBe('The minimum payment is $0.50.');
        expect(text('[data-summary-total]')).toBe('—');
    });
});

describe('starting a payment', () => {
    it('shows field errors and creates nothing when the details are missing', async () => {
        submit();
        await settle(0);

        expect(text('[data-field-error="invoice_number"]')).toBe('Enter your invoice number.');
        expect(text('[data-field-error="amount"]')).toBe('Enter a valid dollar amount, e.g. 125.50');
        expect(server.post('/payments/attempts')).toHaveLength(0);
    });

    it('creates the attempt with an idempotency key, waits for the intent, then mounts the payment element', async () => {
        server.polls = [{ state: 'pending' }, { state: 'pending' }, ...server.polls];

        await fillValidDetails();
        submit();
        await settle(0);
        expect(text('[data-submit-label]')).toBe('Preparing secure payment…');
        expect($('[data-submit]').disabled).toBe(true);

        await settle(5000);

        const [create] = server.post('/payments/attempts');
        expect(create.body).toEqual({ invoice_number: 'INV-1042', amount: '100.98', payment_type: 'card' });
        expect(create.headers['Idempotency-Key']).toMatch(UUID);
        expect(create.headers['X-CSRF-TOKEN']).toBe('csrf-123');

        expect(server.gets().map((c) => c.url)).toEqual(Array(3).fill('/payments/attempts/att-1'));
        expect(stripe.Stripe).toHaveBeenCalledWith('pk_test_123');
        expect(stripe.elements).toHaveBeenCalledWith({ clientSecret: 'pi_1_secret' });
        expect(stripe.create).toHaveBeenCalledWith('payment', { layout: 'tabs' });
        expect(isHidden('[data-step="payment"]')).toBe(false);
        expect($('#invoice_number').readOnly).toBe(true);
        expect($('#amount').readOnly).toBe(true);
        expect($('[data-submit]').disabled).toBe(false);
    });

    it('stops the payment method from being changed while the payment is being prepared', async () => {
        server.polls = [{ state: 'pending' }, { state: 'pending' }, ...server.polls];

        await fillValidDetails();
        submit();
        await settle(0);
        expect(text('[data-submit-label]')).toBe('Preparing secure payment…');

        // The customer clicks the other method while the worker is still creating the intent.
        const ach = document.querySelector('[data-payment-type][value="ach"]');
        expect(ach.disabled).toBe(true);

        await settle(5000);

        expect(document.querySelector('[data-payment-type][value="card"]').checked).toBe(true);
        expect(text('[data-submit-label]')).toBe('Pay $104.30');
    });

    it("shows the server's totals on the pay button", async () => {
        await reachPaymentStep();

        expect(text('[data-summary-total]')).toBe('$104.30');
        expect(text('[data-submit-label]')).toBe('Pay $104.30');
    });

    it('uses the bank wording for ACH', async () => {
        choose('ach');
        await settle(0);
        await reachPaymentStep();

        expect(text('[data-submit-label]')).toMatch(/^Authorize .* bank payment$/);
    });

    it('stops with the message and starts a fresh attempt next time when the server reports failure', async () => {
        server.polls = [{ state: 'failed', message: "We couldn't start your payment. Please refresh the page and try again." }];

        await fillValidDetails();
        submit();
        await settle(1000);

        expect(text('[data-form-error]')).toBe("We couldn't start your payment. Please refresh the page and try again.");
        expect(isHidden('[data-form-error]')).toBe(false);
        expect(isHidden('[data-step="payment"]')).toBe(true);
        expect($('[data-submit]').disabled).toBe(false);

        submit();
        await settle(1000);

        const keys = server.post('/payments/attempts').map((c) => c.headers['Idempotency-Key']);
        expect(keys).toHaveLength(2);
        expect(keys[0]).not.toBe(keys[1]);
    });

    it('treats an already submitted payment as a stop and starts over', async () => {
        server.polls = [{ state: 'done' }];

        await fillValidDetails();
        submit();
        await settle(1000);

        expect(text('[data-form-error]')).toBe('This payment has already been submitted.');
    });

    it('times out after about 75 seconds but keeps the same key so a retry reuses the attempt', async () => {
        server.polls = [{ state: 'pending' }];

        await fillValidDetails();
        submit();
        await settle(80_000);

        expect(text('[data-form-error]')).toBe('This is taking longer than expected. Please try again in a moment.');
        expect($('[data-submit]').disabled).toBe(false);

        server.polls = [{ state: 'ready', client_secret: 'pi_1_secret', amount_cents: 10098, fee_cents: 332, total_cents: 10430 }];
        server.pollIndex = 0;
        submit();
        await settle(2000);

        const keys = server.post('/payments/attempts').map((c) => c.headers['Idempotency-Key']);
        expect(keys).toHaveLength(2);
        expect(keys[0]).toBe(keys[1]);
        expect(isHidden('[data-step="payment"]')).toBe(false);
    });

    it('drops the key after a 409 conflict', async () => {
        let first = true;
        server.create = () => {
            if (first) {
                first = false;

                return { status: 409, body: { message: 'This payment session was already used for a different invoice or amount. Please start again.' } };
            }

            return { status: 202, body: { id: 'att-1', state: 'pending' } };
        };

        await fillValidDetails();
        submit();
        await settle(500);
        expect(text('[data-form-error]')).toMatch(/already used for a different invoice/);

        submit();
        await settle(2000);

        const keys = server.post('/payments/attempts').map((c) => c.headers['Idempotency-Key']);
        expect(keys[0]).not.toBe(keys[1]);
    });

    it('shows validation errors returned by the server next to their fields', async () => {
        server.create = () => ({ status: 422, body: { message: 'The invoice number contains invalid characters.', errors: { invoice_number: ['The invoice number contains invalid characters.'] } } });

        await fillValidDetails();
        submit();
        await settle(500);

        expect(text('[data-field-error="invoice_number"]')).toBe('The invoice number contains invalid characters.');
    });

    it('explains when Stripe.js has not loaded instead of showing a raw error', async () => {
        delete window.Stripe;

        await fillValidDetails();
        submit();
        await settle(2000);

        expect(text('[data-form-error]')).toBe('The secure payment form could not load. Please reload the page and try again.');
        expect(isHidden('[data-step="payment"]')).toBe(true);
        expect($('[data-submit]').disabled).toBe(false);
    });
});

describe('editing details after the payment step opens', () => {
    it('reuses the attempt when the details are unchanged', async () => {
        await reachPaymentStep();

        $('[data-edit-details]').click();
        expect(isHidden('[data-step="payment"]')).toBe(true);
        expect($('#invoice_number').readOnly).toBe(false);

        submit();
        await settle(3000);

        const keys = server.post('/payments/attempts').map((c) => c.headers['Idempotency-Key']);
        expect(keys).toHaveLength(2);
        expect(keys[0]).toBe(keys[1]);
    });

    it('starts a new attempt when the details change', async () => {
        await reachPaymentStep();

        $('[data-edit-details]').click();
        type('#invoice_number', 'INV-9999');
        submit();
        await settle(3000);

        const calls = server.post('/payments/attempts');
        expect(calls[0].headers['Idempotency-Key']).not.toBe(calls[1].headers['Idempotency-Key']);
        expect(calls[1].body.invoice_number).toBe('INV-9999');
    });
});

describe('confirming the payment', () => {
    let redirects;

    beforeEach(() => {
        redirects = [];
        $('#payment-form').addEventListener('payment-form:redirect', (event) => {
            redirects.push(event.detail.url);
            event.preventDefault();
        });
    });

    it('confirms without a full-page redirect, then moves this frame to the result page', async () => {
        await reachPaymentStep();

        submit();
        await settle(0);

        expect(stripe.confirmPayment).toHaveBeenCalledTimes(1);
        const args = stripe.confirmPayment.mock.calls[0][0];
        expect(args.redirect).toBe('if_required');
        expect(args.confirmParams.return_url).toBe('/payments/return/att-1');
        expect(args.elements).toBeDefined();
        expect(redirects).toEqual(['/payments/return/att-1']);
    });

    it('stays disabled after a successful confirmation so it cannot be submitted twice', async () => {
        await reachPaymentStep();

        submit();
        await settle(0);
        submit();
        await settle(0);

        expect(stripe.confirmPayment).toHaveBeenCalledTimes(1);
        expect($('[data-submit]').disabled).toBe(true);
    });

    it('shows the error Stripe gives for an immediate failure and does not leave the page', async () => {
        await reachPaymentStep();
        stripe.confirmPayment.mockResolvedValueOnce({ error: { message: 'Your card was declined.' } });

        submit();
        await settle(0);

        expect(text('[data-form-error]')).toBe('Your card was declined.');
        expect($('[data-submit]').disabled).toBe(false);
        expect(redirects).toEqual([]);
    });

    it('recovers with a generic message if confirming throws', async () => {
        await reachPaymentStep();
        stripe.confirmPayment.mockRejectedValueOnce(new Error('network down'));

        submit();
        await settle(0);

        expect(text('[data-form-error]')).toBe('Something went wrong submitting your payment. Please try again.');
        expect($('[data-submit]').disabled).toBe(false);
        expect(redirects).toEqual([]);
    });
});
