<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Stripe Payment Trial

An embeddable (iframe) invoice payment form. The customer enters an invoice number, amount and payment type (card or ACH), and a processing fee is added on top so the invoice is covered after Stripe's fees. Amounts are handled as integer cents, which is also the unit the Stripe API expects (`amount=1099` is $10.99).

### Fees

Rates live in `config/payments.php` and can be overridden in `.env`.

| Method | Stripe's fee | What we add |
|---|---|---|
| Card | 2.9% + 30¢ | Fee grossed up so the invoice is covered (optional ceiling, see below) |
| ACH | 0.8%, max $5.00 | Fee grossed up, capped at $5.00 |

### How the fee is calculated

Stripe takes its fee from the total we charge, not from the invoice amount, so the fee has to be calculated on the total:

```
card:  total = (invoice + 30¢) ÷ 0.971
ach:   total = invoice ÷ 0.992        (fee capped at $5.00)
```

The total is **rounded up to the next cent** using integer cents and ceiling division (no floats), so we never round down and come up a fraction of a cent short. Examples:

| Invoice | Method | Total charged | Fee added |
|---|---|---|---|
| $100.98 | Card | $104.31 | $3.33 |
| $100.98 | ACH | $101.80 | $0.82 |

Stripe rounds its own fee to whole cents, so the merchant's net can differ from the invoice by a cent. The round-up is deliberately conservative, so it never lands short.

### Decision: no 3% ceiling on card surcharges (needs review before going live)

The goal of the fee is to break even. Stripe's [surcharging guide](https://docs.stripe.com/payments/cards/surcharge) says US card surcharges are limited to **3%** (credit cards only), and card network rules and state laws apply on top. Fully covering a 2.9% + 30¢ fee takes more than 3% of the invoice below about **$2,308**, because the fixed 30¢ is a large share of a small invoice (about 6.1% on a $10 invoice). Capping at 3% means the merchant absorbs the difference, up to about 31¢ per card payment, so breaking even isn't possible within the limit on small invoices.

For this trial we calculate the full cost-recovery fee and **do not enforce the 3% limit by default**. This is an estimate for the demo, not confirmation that the fee can legally be charged. To enforce the limit and accept the shortfall instead, set `PAYMENTS_CARD_MAX_SURCHARGE_PERCENT=3.0` in `.env`. ACH is not affected. Other options, such as a card minimum with small invoices steered to ACH, or cash-discount pricing, remain open.

Left out of this proof of concept:

- Sending the fee to Stripe as a surcharge (`amount_details[surcharge]`, a preview API) with receipts showing the subtotal and surcharge separately. The fee is currently only part of the total charged.
- Debit cards. US law and Stripe allow surcharges on credit cards only, and we can't tell credit from debit until the customer enters the card.
- Checking whether surcharging is permitted in the customer's state, and any required registration with the card networks. This needs a professional review before going live.
- Webhooks. Payment status is read from Stripe when the customer returns, so an ACH payment can stay `processing` until it's checked again.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
