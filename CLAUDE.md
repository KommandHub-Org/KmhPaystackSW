# KmhPaystackSW

Shopware 6 payment plugin integrating Paystack (cards, bank transfer, USSD) with
refunds, webhooks, bank-account verification, and African currency/language
support. PHP namespace root: `Kommandhub\PaystackSW\` → `src/`.

## Commands

All commands run inside the Docker dev stack (see `Makefile`). The plugin lives
at `custom/static-plugins/KmhPaystackSW` inside a Shopware install.

- `make up` / `make down` — start / tear down the stack
- `make test` — PHPUnit (`phpunit.dist.xml`). Filter: `make test FILTER=SomeTest`
- `make test-coverage` — coverage text report
- `make analyse` — PHPStan (`phpstan.dist.neon`, level in that file), `src` only
- `make cs` / `make cs-fix` — php-cs-fixer dry-run / apply
- `make shell` — bash into the app container

Run `make cs-fix && make analyse && make test` before committing.

## Architecture

**Feature-first modules** under `src/`, following Shopware's own plugin layout
(cf. SwagPayPal). A top-level directory *is* a boundary; inside it, flat
Symfony-idiomatic folders (`Service`, `Subscriber`, `Handler`, `Struct`,
`Event`, `Enum`, `Controller`) — no `Application/Domain/Infrastructure` nesting.
One obvious home per class.

- `Checkout/` — the payment flow.
  - `Payment/Handler/` — Shopware payment handler (pay/finalize/refund entry).
  - `Payment/Service/` — orchestration: `PaymentProcessor` (initialize),
    `FinalizeProcessor` (verify + mark paid), `RefundProcessor`,
    `RefundAggregator`, the `TransactionVerification`/`TransactionMetadata`
    processors, `PayloadBuilder`, `TransactionService`, `OrderTransactionService`.
  - `Payment/{Struct,Event,Enum}/` — DTOs, `PaymentFinalizedEvent`,
    `PaystackTransactionStatus`.
  - `Cart/` — `CartValidator` + `Error/`.
- `Webhook/` — `Controller/` (storefront endpoint), `Service/` (signature
  validator, event factory, processor, refund-initialize), `Subscriber/`
  (`ChargeSuccessSubscriber`, `WebhookSubscriber`), `Event/`
  (`charge.success`, `refund.pending`, `refund.processed`).
- `BankVerification/` — `Controller/` + `Service/` (account-number resolution).
- `Administration/Controller/RefundController.php` — admin refund API endpoint.
- `Client/` — the Paystack REST client: `PaystackClient`, `Http/`, and one typed
  `Resource/` class per Paystack endpoint.
- `DataAbstractionLayer/` — order-transaction reader/writer gateways over the DAL.
- `Setting/Service/Config.php`, `Logging/`, `Exception/`, `Util/`
  (`PaystackConstants`, `PaystackCurrencyHelper`), `Installer/` (payment method +
  custom fields) — cross-cutting.
- `Migration/` — add African currencies/languages (Shopware discovers by folder).
- `Resources/` — `config/{services.yml,routes.yml,config.xml}`, admin (Vue) +
  storefront JS, snippets. Built assets in `Resources/public/` are generated —
  never hand-edit.

## Conventions & gotchas

- **DI is autowired** via the `../../*` glob in `services.yml`. Symfony does NOT
  auto-alias an interface to its single implementation — when you add a new
  `*Interface` that is constructor-injected, add an explicit `alias:` entry.
- **Money is minor units at the Paystack boundary.** Never multiply by 100
  inline — always go through `PaystackCurrencyHelper::toMinorUnit()` /
  `fromMinorUnit()`, which know per-currency decimals (NGN=2, XOF/RWF/JPY=0,
  KWD=3, …). Zero- and three-decimal currencies are in scope.
- **Custom-field keys** live only in `PaystackConstants` (`paystack_reference`
  is the webhook lookup key on the order transaction).
- **Verification must match status AND amount AND currency** before marking an
  order paid — the reference comes from an attacker-controllable callback query.
- New Paystack API calls go through a typed `Client/Resource/` class, not raw HTTP.
- Keep a change inside its feature module; reach across modules through a
  service, not by deep-linking another module's internals.
- Tests mirror `src/` under `tests/Unit/` (+ `tests/Integration/`). Add a test
  with each behavior change.
