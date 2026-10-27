const form = document.querySelector('[data-payment-form]');

if (form) {
    initPaymentForm(form);
}

function initPaymentForm(form) {
    const $ = (selector) => form.querySelector(selector);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    const el = {
        error: $('[data-form-error]'),
        invoice: $('#invoice_number'),
        amount: $('#amount'),
        types: form.querySelectorAll('[data-payment-type]'),
        detailsStep: $('[data-step="details"]'),
        paymentStep: $('[data-step="payment"]'),
        paymentElement: $('[data-payment-element]'),
        editDetails: $('[data-edit-details]'),
        submit: $('[data-submit]'),
        submitLabel: $('[data-submit-label]'),
        spinner: $('[data-submit-spinner]'),
        summaryAmount: $('[data-summary-amount]'),
        summaryFee: $('[data-summary-fee]'),
        summaryTotal: $('[data-summary-total]'),
    };

    const urls = {
        quote: form.dataset.quoteUrl,
        intent: form.dataset.intentUrl,
    };

    const AMOUNT_PATTERN = /^\d{1,3}(,?\d{3})*(\.\d{1,2})?$|^\.\d{1,2}$/;
    const QUOTE_DEBOUNCE_MS = 350;
    const money = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

    // Per-method behaviour for the step after the details are entered.
    const methods = {
        card: {
            continueLabel: 'Continue to card payment',
            payLabel: (total) => `Pay ${total}`,
        },
        ach: {
            continueLabel: 'Continue to bank account',
            payLabel: (total) => `Authorize ${total} bank payment`,
        },
    };

    const state = {
        step: 'details',
        quote: null,
        quoteKey: null,
        quoteSeq: 0,
        quoteAbort: null,
        quoteTimer: null,
        quotePromise: Promise.resolve(),
        stripe: null,
        elements: null,
        returnUrl: null,
        busy: false,
    };

    const currentType = () => [...el.types].find((r) => r.checked)?.value ?? 'card';
    const formatCents = (cents) => money.format(cents / 100);

    function showError(message) {
        el.error.textContent = message ?? '';
        el.error.classList.toggle('hidden', !message);
    }

    function setFieldError(name, message) {
        const target = form.querySelector(`[data-field-error="${name}"]`);
        if (target) target.textContent = message ?? '';
    }

    function clearErrors() {
        showError(null);
        ['invoice_number', 'amount', 'payment_type'].forEach((name) => setFieldError(name, null));
    }

    function setBusy(busy) {
        state.busy = busy;
        el.submit.disabled = busy;
        el.spinner.classList.toggle('hidden', !busy);
    }

    function renderSummary(quote) {
        state.quote = quote;
        el.summaryAmount.textContent = quote ? formatCents(quote.amount_cents) : '—';
        el.summaryFee.textContent = quote ? formatCents(quote.fee_cents) : '—';
        el.summaryTotal.textContent = quote ? formatCents(quote.total_cents) : '—';
        renderSubmitLabel();
    }

    function renderSubmitLabel() {
        const method = methods[currentType()];
        el.submitLabel.textContent =
            state.step === 'payment' && state.quote
                ? method.payLabel(formatCents(state.quote.total_cents))
                : method.continueLabel;
    }

    async function request(url, payload, signal) {
        const response = await fetch(url, {
            method: 'POST',
            signal,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            const error = new Error(data.message || 'Something went wrong. Please try again.');
            error.errors = data.errors ?? {};
            throw error;
        }

        return data;
    }

    // Runs whenever the amount or payment method changes.
    function scheduleQuote({ immediate = false } = {}) {
        clearTimeout(state.quoteTimer);
        state.quoteAbort?.abort();

        const amount = el.amount.value.trim();
        const type = currentType();

        // Invalidate any in-flight quote so a stale response can't overwrite the UI.
        const seq = ++state.quoteSeq;
        state.quoteKey = null;

        if (!amount || !AMOUNT_PATTERN.test(amount)) {
            renderSummary(null);
            setFieldError('amount', amount ? 'Enter a valid dollar amount, e.g. 125.50' : null);
            state.quotePromise = Promise.resolve();
            return;
        }

        setFieldError('amount', null);
        el.summaryFee.textContent = '…';
        el.summaryTotal.textContent = '…';

        state.quotePromise = new Promise((resolve) => {
            state.quoteTimer = setTimeout(async () => {
                const controller = new AbortController();
                state.quoteAbort = controller;

                try {
                    const quote = await request(urls.quote, { amount, payment_type: type }, controller.signal);
                    if (seq === state.quoteSeq) {
                        state.quoteKey = `${type}|${amount}`;
                        renderSummary(quote);
                    }
                } catch (error) {
                    if (error.name === 'AbortError' || seq !== state.quoteSeq) return;
                    renderSummary(null);
                    setFieldError('amount', error.errors?.amount?.[0] ?? error.message);
                } finally {
                    resolve();
                }
            }, immediate ? 0 : QUOTE_DEBOUNCE_MS);
        });
    }

    function validateDetails() {
        clearErrors();
        let valid = true;

        if (!el.invoice.value.trim()) {
            setFieldError('invoice_number', 'Enter your invoice number.');
            valid = false;
        }

        const amount = el.amount.value.trim();
        if (!AMOUNT_PATTERN.test(amount) || Number(amount.replace(/,/g, '')) <= 0) {
            setFieldError('amount', 'Enter a valid dollar amount, e.g. 125.50');
            valid = false;
        }

        return valid;
    }

    function lockDetails(locked) {
        [el.invoice, el.amount].forEach((input) => (input.readOnly = locked));
        el.types.forEach((radio) => (radio.disabled = locked));
    }

    async function startPaymentStep() {
        if (!validateDetails()) return;

        setBusy(true);
        try {
            // Make sure the displayed quote matches the current inputs before charging.
            const key = `${currentType()}|${el.amount.value.trim()}`;
            await state.quotePromise;
            if (state.quoteKey !== key) {
                scheduleQuote({ immediate: true });
                await state.quotePromise;
            }
            if (!state.quote || state.quoteKey !== key) return;

            const intent = await request(urls.intent, {
                invoice_number: el.invoice.value.trim(),
                amount: el.amount.value.trim(),
                payment_type: currentType(),
            });

            mountPaymentElement(intent);
            state.step = 'payment';
            lockDetails(true);
            el.paymentStep.classList.remove('hidden');
            renderSubmitLabel();
        } catch (error) {
            Object.entries(error.errors ?? {}).forEach(([name, messages]) => setFieldError(name, messages[0]));
            showError(error.message);
        } finally {
            setBusy(false);
        }
    }

    function mountPaymentElement({ client_secret: clientSecret, return_url: returnUrl, publishable_key: key }) {
        state.stripe ??= window.Stripe(key || form.dataset.stripeKey);
        state.returnUrl = returnUrl;
        state.elements = state.stripe.elements({ clientSecret });
        state.elements.create('payment', { layout: 'tabs' }).mount(el.paymentElement);
    }

    function backToDetails() {
        state.elements = null;
        el.paymentElement.replaceChildren();
        el.paymentStep.classList.add('hidden');
        state.step = 'details';
        lockDetails(false);
        clearErrors();
        renderSubmitLabel();
    }

    async function confirmPayment() {
        clearErrors();
        setBusy(true);

        const { error } = await state.stripe.confirmPayment({
            elements: state.elements,
            confirmParams: { return_url: state.returnUrl },
        });

        // Only reached on immediate failure; success redirects to return_url.
        if (error) showError(error.message);
        setBusy(false);
    }

    el.amount.addEventListener('input', () => scheduleQuote());
    el.types.forEach((radio) =>
        radio.addEventListener('change', () => {
            renderSubmitLabel();
            scheduleQuote({ immediate: true });
        }),
    );
    el.editDetails.addEventListener('click', backToDetails);

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (state.busy) return;
        state.step === 'details' ? startPaymentStep() : confirmPayment();
    });

    renderSubmitLabel();
    if (el.amount.value.trim()) scheduleQuote({ immediate: true });
}
