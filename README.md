# Paystack Payment for Shopware 6

[![License: Apache 2.0](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](https://opensource.org/licenses/Apache-2.0)
[![Shopware](https://img.shields.io/badge/Shopware-6.6%20%7C%206.7-blue.svg)](https://shopware.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)](https://www.php.net)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](https://phpstan.org)

<p align="center">
  <a href="https://kommandhub.com" target="_blank">
    <img src="src/Resources/config/kommandhub.png" alt="Kommandhub Logo">
  </a>
</p>

A production-grade **Shopware 6 payment plugin** that integrates the **[Paystack](https://paystack.com)** payment gateway, enabling merchants across Africa to accept secure online payments through cards, bank transfers, USSD, and mobile money.

Developed by [Kommandhub Limited](https://kommandhub.com).

> **Independent integration.** This is an independent, third-party plugin. It is **not** affiliated with, endorsed by, sponsored by, certified by, or officially supported by Paystack. "Paystack" and the Paystack logo are trademarks of their respective owner and are used here only to identify the payment gateway this plugin connects to. See [Trademarks & Disclaimer](#trademarks--disclaimer).

This document is the technical reference for developers **contributing to** the plugin. If you only want to install and configure it on a live shop, the [Installation](#installation) and [Configuration](#configuration) sections are enough.

---

## Table of Contents

- [Project Overview](#project-overview)
- [Key Features](#key-features)
- [Supported Payment Channels](#supported-payment-channels)
- [Architecture Overview](#architecture-overview)
- [Technology Stack](#technology-stack)
- [Directory Structure](#directory-structure)
- [System Requirements](#system-requirements)
- [Installation](#installation)
- [Local Development Setup](#local-development-setup)
- [Docker & Docker Compose](#docker--docker-compose)
- [Makefile Commands](#makefile-commands)
- [Configuration Options](#configuration-options)
- [Build & Asset Compilation](#build--asset-compilation)
- [Testing](#testing)
- [Code Quality](#code-quality)
- [Data Handling](#data-handling)
- [Logging & Debugging](#logging--debugging)
- [Security Considerations](#security-considerations)
- [Performance Considerations](#performance-considerations)
- [Deployment](#deployment)
- [CI/CD](#cicd)
- [Version Compatibility](#version-compatibility)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Coding Standards](#coding-standards)
- [Roadmap](#roadmap)
- [License](#license)
- [Trademarks & Disclaimer](#trademarks--disclaimer)
- [Support](#support)

---

## Project Overview

The plugin adds Paystack as a native Shopware 6 payment method. It handles the full payment lifecycle:

1. **Initialize** a Paystack transaction and redirect the customer to Paystack's hosted checkout.
2. **Finalize** the payment on return by verifying the transaction against the Paystack API — matching **status, amount, and currency** before the order is marked paid.
3. **Reconcile** payments asynchronously via signed webhooks (for cases where the customer never returns from the redirect).
4. **Refund** orders (full or partial) from the Administration, guarded by a dedicated permission and a server-side over-refund check.

It also provides customer bank-account verification (via Paystack's account-resolution endpoint) and correct money handling for the currencies Paystack supports, including zero-decimal (e.g. XOF, RWF) and three-decimal (e.g. KWD) currencies.

> The plugin does **not** create currencies or languages in your shop. Amounts are converted correctly for whichever currency an order uses; adding a currency (e.g. XOF) or a language to Shopware remains a normal shop configuration step.

---

## Key Features

- Native Shopware 6 payment method with Paystack hosted checkout.
- Payment verification that validates **status + amount + currency** (the return reference is attacker-controllable, so all three are checked).
- Asynchronous reconciliation through signed webhooks: `charge.success`, `refund.pending`, `refund.processed`.
- Full and partial refunds from the order detail page, with a **server-side over-refund cap**.
- A dedicated **`paystack.refund`** admin permission, assignable to roles (depends on the order editor permission).
- Customer bank-account verification in the account area.
- Correct amount handling for every currency Paystack supports, including 0-decimal (e.g. XOF, RWF) and 3-decimal (e.g. KWD) currencies — amounts always cross the Paystack boundary in the right minor unit.
- Plugin interface translated into English, German and French (administration + storefront).
- Optional Paystack split payments (subaccount / charges bearer).
- Configurable, level-filtered logging with a sandbox/live mode toggle.

---

## Supported Payment Channels

Availability depends on your Paystack account and configuration:

- Debit & credit cards
- Bank transfers
- USSD
- QR codes
- Mobile money (Ghana, Kenya, and others)

---

## Architecture Overview

The plugin uses a **feature-first** layout that mirrors Shopware's own plugins (for example, SwagPayPal). Each top-level directory under `src/` is a bounded feature; inside it, code is organized into flat, Symfony-idiomatic folders (`Service`, `Subscriber`, `Handler`, `Struct`, `Event`, `Enum`, `Controller`). There is no `Application/Domain/Infrastructure` layering — a top-level module *is* the boundary, and every class has one obvious home.

**Guiding principles**

- Shopware entry points (payment handler, controllers, subscribers) stay thin and delegate to services.
- A change stays inside its feature module; cross-module access goes through a service, not by deep-linking another module's internals.
- New Paystack API calls go through a typed `Client/Resource/` class, never raw HTTP.
- Money is always in **minor units** at the Paystack boundary and converted only through `PaystackCurrencyHelper`.

**Payment flow**

```text
Customer selects Paystack
        │
        ▼
PaymentProcessor.initialize()  ──►  Paystack transaction created, reference persisted
        │
        ▼
Redirect to Paystack hosted checkout
        │
        ▼
Customer pays ──► redirect back ──► FinalizeProcessor.verify()
        │                                   (status == success AND amount AND currency match)
        ▼
Order transaction marked "paid"

If the customer never returns, the `charge.success` webhook reconciles the order
using the reference stored at initialize time (idempotent: it no-ops if already paid).
```

**Refund flow**

```text
Admin opens the order → Paystack tab → Refund
        │  (client gate: paystack.refund permission + refundable balance)
        ▼
RefundController.refund()  ──►  server gate: _acl paystack.refund, min & max (over-refund) checks
        │
        ▼
Paystack refund created ──► refund.pending / refund.processed webhooks update Shopware refund records
```

---

## Technology Stack

| Layer | Technology |
| --- | --- |
| Language | PHP 8.2+ (8.3 in the dev container) |
| Framework | Shopware 6 (`shopware/core`, `shopware/storefront`), Symfony |
| Admin UI | Vue, Shopware Administration, Meteor Component Library (`mt-*`) |
| Admin build | Vite (output committed under `Resources/public/`) |
| Storefront | Twig + vanilla JS plugin (output under `Resources/app/storefront/dist/`) |
| Testing | PHPUnit 11 |
| Static analysis | PHPStan (level 9) |
| Code style | PHP-CS-Fixer |
| Local environment | Docker + Docker Compose (`dockware/shopware`) |
| CI | GitHub Actions |

---

## Directory Structure

```text
KmhPaystackSW/
├── src/
│   ├── KmhPaystackSW.php          # Plugin base class (lifecycle hooks)
│   ├── Administration/
│   │   └── Controller/RefundController.php  # Admin refund API endpoint
│   ├── BankVerification/
│   │   ├── Controller/                   # Storefront account-verification endpoints
│   │   └── Service/                      # Bank validation
│   ├── Checkout/
│   │   ├── Payment/
│   │   │   ├── Handler/                  # Shopware payment handler (pay/finalize/refund)
│   │   │   ├── Service/                  # PaymentProcessor, FinalizeProcessor,
│   │   │   │                             #   RefundProcessor, RefundAggregator,
│   │   │   │                             #   Transaction{Verification,Metadata}Processor,
│   │   │   │                             #   PayloadBuilder, OrderTransactionService, …
│   │   │   ├── Struct/                   # DTOs (e.g. PaystackInitializationResponse)
│   │   │   ├── Event/                    # PaymentFinalizedEvent
│   │   │   └── Enum/                     # PaystackTransactionStatus
│   │   └── Cart/                         # CartValidator + Error/
│   ├── Client/                           # Paystack REST client
│   │   ├── PaystackClient.php
│   │   ├── Http/                         # HttpClientInterface + PaystackHttpClient
│   │   └── Resource/                     # One typed class per Paystack endpoint
│   ├── DataAbstractionLayer/             # Order-transaction reader/writer gateways
│   ├── Webhook/
│   │   ├── Controller/                   # Storefront webhook endpoint
│   │   ├── Service/                      # Signature validator, event factory, processor
│   │   ├── Subscriber/                   # ChargeSuccessSubscriber, WebhookSubscriber
│   │   └── Event/                        # WebhookEvent + charge/refund events
│   ├── Setting/Service/Config.php        # Typed access to system configuration
│   ├── Logging/ConfigurableLogger.php    # Level-filtered logger
│   ├── Exception/                        # Domain exceptions
│   ├── Util/                             # PaystackConstants, PaystackCurrencyHelper
│   ├── Installer/                        # Payment method + custom fields installers
│   └── Resources/
│       ├── config/                       # services.yml, routes.yml, config.xml
│       ├── snippet/                      # Storefront snippets
│       ├── views/                        # Twig templates
│       ├── app/administration/           # Vue admin source (src/) + built assets (public/)
│       └── app/storefront/               # Storefront JS source + dist
├── tests/
│   ├── Unit/                             # Mirrors src/, no Shopware kernel
│   └── Integration/                      # Controller/plugin tests
├── .github/workflows/php.yml             # CI pipeline
├── composer.json
├── phpstan.dist.neon
├── phpunit.dist.xml
├── .php-cs-fixer.dist.php
├── docker-compose.yml
├── Makefile
├── CHANGELOG.md                          # English; CHANGELOG_<locale>.md for translations
└── CLAUDE.md                             # Contributor conventions (read this too)
```

> Assets under `Resources/public/` and `Resources/app/storefront/dist/` are **generated**. Never hand-edit them — change the source in `Resources/app/**/src/` and rebuild.

---

## System Requirements

- **Shopware**: `~6.6.0` or `~6.7.0`
- **PHP**: `8.2+`
- **Composer**: 2.x
- **Paystack account**: <https://dashboard.paystack.com/#/signup>
- **For local development**: Docker and Docker Compose
- **For admin/storefront asset builds**: Node.js (provided inside the dev container)

---

## Installation

### Via Composer (recommended)

```bash
composer require kommandhub/paystack-sw
bin/console plugin:refresh
bin/console plugin:install --activate KmhPaystackSW
bin/console cache:clear
```

### Manual upload

1. Build a ZIP containing at least `src/` and `composer.json`.
2. In the Administration, go to **Extensions → My Extensions → Upload Extension**.
3. Install and activate **Paystack Payment**.

On first installation the **Paystack Payment** method is assigned to all of your sales channels and **Sandbox Mode is on by default**, so a fresh install never talks to live Paystack until you switch it off. The method cannot be used for an order until a secret key is configured. If you only want it on some channels, adjust the assignment in the sales channel settings. Updating the plugin never re-adds the method to a channel you removed it from.

Paystack only accepts currencies enabled on your Paystack account (for example NGN, GHS, ZAR, KES), and the plugin does not create currencies. Make sure the currency a customer checks out in is both enabled on your Paystack account and added to the sales channel.

---

## Local Development Setup

The repository ships a Docker Compose stack based on [`dockware`](https://dockware.io) that mounts this plugin into a full Shopware install.

```bash
# 1. Clone the repository
git clone <repository-url> KmhPaystackSW
cd KmhPaystackSW

# 2. Start the stack (builds the container and prepares the shop)
make up

# 3. Install and activate the plugin inside the container
make shell
bin/console plugin:refresh
bin/console plugin:install --activate KmhPaystackSW
bin/console cache:clear
exit
```

The plugin directory is mounted at `/var/www/html/custom/static-plugins/KmhPaystackSW`; `.git/`, `node_modules/`, and `vendor/` are excluded from the mount. Changes to source files on the host are reflected immediately in the container.

Default dockware credentials:

- **Admin**: user `admin`, password `shopware`
- **Database**: user `root`, password `root`, database `shopware`

---

## Docker & Docker Compose

The stack is defined in [`docker-compose.yml`](docker-compose.yml) and built from
a small [`Dockerfile`](Dockerfile):

- **Base image**: `dockware/shopware:6.7.8.0`
- **Added tooling**: [`shopware-cli`](https://sw-cli.fos.gg/) — the static binary
  is copied from the upstream `shopware/shopware-cli:bin` image (latest stable,
  ~50 MB, multi-arch), so no package manager or cleanup is involved. The build
  runs `shopware-cli --version` so a broken install fails the image build rather
  than the first `make validate-plugin`. Pin it for a reproducible build by
  passing `--build-arg SHOPWARE_CLI_IMAGE=shopware/shopware-cli:bin@sha256:<digest>`.
- **Container**: `kmh-paystack-plugin`
- **PHP**: 8.3 (`XDEBUG_ENABLED` toggle available)
- **Persistent volume**: `database` (MySQL data)

```bash
make up        # build + start + prepare
make down      # stop and REMOVE volumes (wipes the database)
make shell     # open a shell in the container
```

> **No host port is published by default.** To reach the Administration from your browser, add a mapping such as `ports: ["80:80"]` to the `shopware` service and reload with `docker compose up -d`. Do **not** use `make restart` for this — it runs `docker compose down -v`, which deletes the database volume and the installed shop.

---

## Makefile Commands

All targets run the underlying tools inside the running container.

| Command | Description |
| --- | --- |
| `make up` | Build, start, and prepare the Shopware container |
| `make down` | Stop the container and remove volumes (wipes the DB) |
| `make build` | Rebuild the container image |
| `make restart` | `down` + `up` (wipes the DB) |
| `make shell` | Open a bash shell in the container |
| `make plugin-list` | List installed plugins |
| `make test` | Run PHPUnit. Filter with `make test FILTER="--filter SomeTest"` |
| `make test-coverage` | Run PHPUnit with a text coverage report |
| `make cs` | PHP-CS-Fixer dry run (no changes) |
| `make cs-fix` | PHP-CS-Fixer, applying fixes |
| `make analyse` | PHPStan static analysis on `src/` |
| `make fixture-load` | Load test fixtures |
| `make resync` | Sync the test config into the shop root |
| `make prepare` | Full project preparation (run by `make up`) |
| `make validate-plugin` | Validate the plugin with `shopware-cli` (`--full --store-compliance`) |
| `make cli` | Run any `shopware-cli` command: `make cli ARGS="extension get-version ."` |
| `make changelog` | Render `CHANGELOG.md` as the Shopware Store would display it |
| `make zip` | Build a distributable plugin zip into `build/` |

`shopware-cli` ships inside the container image (see [Docker & Docker Compose](#docker--docker-compose)), so these run against the same PHP version and installed Shopware as the tests — not whatever is on the host. No local install is required.

**Before committing, run:**

```bash
make cs-fix && make analyse && make test
```

---

## Configuration Options

Configure the plugin under **Extensions → My Extensions → Paystack → Configuration**. Options are stored in Shopware's system configuration under the `KmhPaystackSW.config.*` domain and read through `Setting\Service\Config`.

| Key | Type | Purpose |
| --- | --- | --- |
| `apiSecretKey` | password | Live secret key (`sk_live_...`) |
| `enableSandbox` | bool | Use the sandbox/test key instead of live (**on by default** for new installs) |
| `apiSecretKeySandbox` | password | Test secret key (`sk_test_...`) |
| `enableSplitPayment` | bool | Enable Paystack split payments |
| `subaccountCode` | text | Split payment subaccount code |
| `splitCode` | text | Split payment split code |
| `splitPaymentTransactionCharge` | int | Flat transaction charge for split payments |
| `paystackChargesBearer` | select | Who bears fees: Account (merchant) or Subaccount |
| `metaData` | multi-select | Extra order/customer data sent to Paystack |
| `collectBankData` | bool | Collect customer bank data in the account area |
| `showBvnField` | bool | Show the BVN field |
| `requireBvn` | bool | Make the BVN field required |
| `refundEnabled` | bool | Allow refunds from the Administration |
| `minimumRefundAmount` | int | Minimum refund amount, in minor units |
| `enableDebugging` | bool | Enable verbose logging |
| `logLevels` | multi-select | PSR-3 levels to log when debugging is enabled |

> Secret keys are stored in Shopware's system configuration, never in code. There is no public key setting — Paystack initialization is server-to-server using the secret key.

---

## Build & Asset Compilation

PHP has no build step beyond `composer install`. Frontend assets do, and their compiled output is committed to the repository.

**Administration (Vue → Vite)**, output to `Resources/public/administration/`:

```bash
make shell
./bin/build-administration.sh
```

**Storefront**, output to `Resources/app/storefront/dist/`:

```bash
make shell
./bin/build-storefront.sh
```

Rebuild the relevant bundle whenever you change source under `Resources/app/administration/src/` or `Resources/app/storefront/src/`, and commit the regenerated assets together with the source. Keeping route names, service IDs, and custom-field keys stable means most PHP changes require **no** asset rebuild.

---

## Testing

Tests live under `tests/` and mirror `src/`. Each behavior change should ship with a test.

```bash
make test                              # full suite
make test FILTER="--filter RefundControllerTest"
make test-coverage                     # text coverage report
```

- **Unit tests** (`tests/Unit/`) use plain `PHPUnit\Framework\TestCase` with mocks and require no Shopware kernel. These run in CI.
- **Integration tests** (`tests/Integration/`) exercise controllers and the plugin lifecycle. Those tagged `#[Group('kernel')]` need a booted Shopware kernel and database, so they run locally via `make test` rather than in the lightweight CI job.
- **End-to-end**: there is no automated E2E suite yet. The payment, refund and webhook flows are validated manually against a running shop with a Paystack **test** key. Automating this is on the [roadmap](#roadmap).

Pure business logic in the admin (`Resources/app/administration/src/service/refund-calculator.js`) has a companion Jest-style spec (`refund-calculator.spec.js`) for a full Shopware admin test runner.

---

## Code Quality

| Tool | Command | Config |
| --- | --- | --- |
| PHPStan (level 9) | `make analyse` | `phpstan.dist.neon` |
| PHP-CS-Fixer | `make cs` / `make cs-fix` | `.php-cs-fixer.dist.php` |
| PHPUnit | `make test` | `phpunit.dist.xml` |

CI enforces PHP lint, PHPStan, code style, unit + mockable integration tests, and a 100% line-coverage threshold. Kernel-dependent tests are excluded from CI.

---

## Data Handling

The plugin ships **no schema migrations** — it does not alter the database schema. All setup is performed through Shopware's plugin lifecycle by dedicated installers:

- **Payment method**: created and kept in sync by `Installer\PaymentMethodInstaller`.
- **Custom fields** (Paystack reference, amount, currency, fee, etc.): created by `Installer\CustomFieldsInstaller` during install/update.

These run on `install()`/`update()` and are reverted appropriately on uninstall (custom fields are removed only when the user does not keep plugin data).

> The plugin's `update()` lifecycle hook re-runs the installers, which migrates the stored payment-method `handlerIdentifier` if the handler class moves between versions. Without this, a plugin **update** (as opposed to a fresh install) would leave a dangling handler identifier and break checkout.

---

## Logging & Debugging

Logging goes through `Logging\ConfigurableLogger`, wired to the `paystack_channel` Monolog channel (writes to `var/log/`).

- Set `enableDebugging` and pick `logLevels` to control verbose output.
- **Error, critical, alert, and emergency messages are always written**, regardless of the debugging toggle, so production keeps a trail of failures (webhook signature rejections, verification/refund errors).
- Enable Xdebug in the container by setting `XDEBUG_ENABLED=1` in `docker-compose.yml` and restarting.

---

## Security Considerations

- **Webhook authenticity**: `WebhookSignatureValidator` verifies the `x-paystack-signature` HMAC over the raw request body using `hash_equals` (timing-safe) and rejects missing signatures or a missing secret.
- **Payment verification**: an order is marked paid only when the verified transaction matches **status AND amount AND currency**. The return `reference` comes from an attacker-controllable callback, so it is never trusted on its own.
- **Refund authorization**: the refund endpoint requires the dedicated `paystack.refund` privilege (route `_acl`), and the admin action is gated by the same permission.
- **Over-refund protection**: the server recomputes the refundable balance (captures/transaction total minus completed and in-progress refunds) and rejects amounts above it and below the configured minimum. The client-side bound is a UX aid only.
- **Secrets**: API keys live in Shopware's system configuration, not in the codebase or in URLs.
- **Money handling**: amounts cross the Paystack boundary in minor units via `PaystackCurrencyHelper`, which knows per-currency decimals — never multiply by 100 inline.

---

## Performance Considerations

- Order/transaction reads use targeted DAL criteria with only the associations they need.
- The admin detail view guards against concurrent capture/refund loads and loads plugin configuration reactively rather than on a fixed lifecycle tick.
- Refund math is a pure, memoizable computation isolated in `refund-calculator.js`.

---

## Deployment

1. Ship the plugin via Composer (`composer require kommandhub/paystack-sw`) or an Administration upload.
2. Run:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:update KmhPaystackSW   # migrates handler identifier + custom fields
   bin/console cache:clear
   ```
3. Ensure compiled Administration and Storefront assets are built and committed as part of the release.
4. Configure live secret keys and disable sandbox mode.
5. Grant the **Paystack → Process Paystack refunds** permission to the roles that should be able to issue refunds (the built-in admin role already has all privileges).

Validate a release build for the Shopware Store from inside the container, where
`shopware-cli` runs against the installed Shopware and full vendor tree:

```bash
make validate-plugin
# equivalently: make cli ARGS="extension validate . --full --store-compliance"
```

Running the same command against a host-only `shopware-cli` reports false
positives (see the PHPStan note under [Troubleshooting](#troubleshooting)) —
the container has the dependencies it needs to resolve Shopware's classes.

---

## CI/CD

GitHub Actions (`.github/workflows/php.yml`) runs on pushes to `main`/`develop` and on pull requests:

1. Validate `composer.json`.
2. Install dependencies.
3. Lint all PHP files.
4. PHPStan (level 9).
5. PHP-CS-Fixer (dry run).
6. PHPUnit unit tests with clover coverage.
7. Enforce a minimum coverage threshold (85%) via `coverage-check`.

Integration tests are excluded from this job because they need a booted Shopware kernel; run them locally with `make test`.

---

## Version Compatibility

| Plugin | Shopware | PHP |
| --- | --- | --- |
| `0.9.0-beta.x` | 6.6 and 6.7 | 8.2+ |

A single plugin release supports both Shopware 6.6 and 6.7 (`shopware/core: ~6.6.0 || ~6.7.0`).

> **Pre-1.0 status:** the plugin is on a `0.x` version while it completes sandbox/staging validation (see [Roadmap](#roadmap)). Namespaces and the public API may still change before `1.0.0`. Once testing is complete and all critical issues are resolved, this will be promoted to `1.0.0` and submitted to the Shopware Store.

---

## Troubleshooting

**Order status not updating after payment**
- Confirm the webhook endpoint is reachable from Paystack and the secret key matches the mode (test vs live).
- Check `var/log/` for verification or signature errors.

**Test payments fail with "Insufficient Funds"**
- Paystack's sandbox test cards reject large amounts. Test with small orders (a few hundred in the currency, not hundreds of thousands) — an order of NGN 150 succeeds where NGN 790,000 is declined.

**Payment fails with "No active channel to process transaction" or "Currency not supported by merchant"**
- The order currency is not enabled on your Paystack account. Enable it in the Paystack dashboard, or have customers check out in a currency your account supports.

**Status emails say "New document for your order" or contain "he status…"**
- These are Shopware's own default mail templates (they are not shipped by this plugin). Edit them under **Settings → Shop → Email templates** — for example the templates for "Payment status paid / cancelled / open".

**A payment was made but the order stayed open**
- Paystack's webhook delivers `charge.success` even when the customer never returned to the shop, and the order is then finalized. Orders are matched by the stored reference or, for an earlier retry attempt, by the order transaction id the plugin sends in the Paystack metadata. Check the webhook URL and secret key.

**Plugin not visible in the Administration**
```bash
bin/console plugin:refresh
```

**Stale Administration UI or DI errors after changes**
```bash
bin/console cache:clear
```

**Refund action not shown**
- Ensure `refundEnabled` is on, the role has the `paystack.refund` permission, and the transaction still has a refundable balance.

**PHPStan can't find `Shopware\Storefront\...` during `shopware-cli` validation**
- `shopware/storefront` must be declared in `require` (it is). Do not point `scanDirectories` at the host's `vendor/` — let Composer install and autoload it.

---

## Contributing

1. Create a feature branch off `develop`.
2. Keep changes inside the relevant feature module; reach across modules through services.
3. Add or update tests alongside behavior changes (mirror the `src/` path under `tests/`).
4. Rebuild admin/storefront assets if you touched their source, and commit the output.
5. Run the full local gate before opening a PR:
   ```bash
   make cs-fix && make analyse && make test
   ```
6. Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages (`feat:`, `fix:`, `refactor:`, `test:`, …).
7. Open a pull request against `develop`.

Please also read [`CLAUDE.md`](CLAUDE.md) for the module boundaries and project-specific conventions.

---

## Coding Standards

- **PHP style**: PSR-12, enforced by PHP-CS-Fixer (`.php-cs-fixer.dist.php`).
- **Static analysis**: PHPStan level 9 must pass with no new errors.
- **Architecture**: feature-first modules; one obvious home per class; thin Shopware entry points delegating to services.
- **Paystack API**: add new calls as typed classes under `Client/Resource/`, never as raw HTTP.
- **Money**: always convert through `Util\PaystackCurrencyHelper`; amounts are minor units at the Paystack boundary.
- **Custom-field keys**: defined only in `Util\PaystackConstants` (`paystack_reference` is the webhook lookup key).
- **Dependency injection**: services are autowired via the `../../*` glob in `services.yml`. Symfony does not auto-alias an interface to its single implementation — when you add a constructor-injected `*Interface`, add an explicit `alias:` entry.

---

## Roadmap

- Complete the migration of Administration components to the Meteor Component Library (`sw-*` → `mt-*`), including data grids and modals.
- Make the storefront bank-verification feature optional for headless setups (decouple from `StorefrontController`).
- Trim unused Paystack API resource classes to the endpoints the plugin actually uses.
- Add automated end-to-end coverage. Planned in layers, cheapest first:
  1. **Paystack sandbox contract tests** — call the real API with an `sk_test_` key to prove the client matches Paystack's contract and that amounts survive the round trip in minor units.
  2. **Webhook endpoint tests** — POST signed/unsigned payloads over HTTP (Paystack signs with HMAC-SHA512 of the raw body using the secret key, in `x-paystack-signature`).
  3. **Browser tests** — storefront checkout through Paystack's hosted page and the Administration refund flow.
  Notes for whoever picks this up: gate the suite behind an env var so it skips by default, refuse to run against a live key, exclude it from CI, and remember that real webhook delivery needs a public tunnel **and** the tunnel hostname registered as a Shopware sales-channel domain (otherwise Shopware answers `400` before the controller runs).

---

## License

Licensed under the **Apache License 2.0**. See [LICENSE](LICENSE) and [NOTICE](NOTICE) for details.

Apache-2.0 was chosen over a simpler permissive licence for its explicit patent grant, its explicit reservation of trademark rights (§6), and the `NOTICE` mechanism that carries attribution downstream into forks. The licence covers this plugin's **own source code only**. It grants **no rights** in the KommandHub name or logo (see [TRADEMARKS.md](TRADEMARKS.md)), nor in Paystack's trademarks, logos, or services (see [Trademarks & Disclaimer](#trademarks--disclaimer)).

---

## Trademarks & Disclaimer

This plugin is an **independent, third-party integration** developed and maintained by [Kommandhub Limited](https://kommandhub.com). It is **not** affiliated with, endorsed by, sponsored by, certified by, or officially supported by Paystack or any of its affiliates.

"Paystack", the Paystack logo, and any related names, marks, and logos are trademarks of their respective owner. "Shopware" is a registered trademark of shopware AG. All other trademarks referenced in this project are the property of their respective owners. These marks are used in this project solely for **nominative purposes** — to identify the third-party payment gateway and the platform that this plugin integrates with — and their use does not imply any endorsement, partnership, or affiliation.

Use of the Paystack payment gateway is subject to Paystack's own terms of service and agreements, which are between the merchant and Paystack. This plugin merely provides a technical integration and makes no warranty regarding Paystack's services. To use it you must hold your own valid Paystack account and API credentials.

**KommandHub's own marks** — the "KommandHub" name and logo — are trademarks of Kommandhub Limited. The open-source licence covers the code, not the brand: a fork must be **rebranded** before redistribution. The full policy is in [TRADEMARKS.md](TRADEMARKS.md).

---

## Support

- Email: [info@kommandhub.com](mailto:admin@kommandhub.com)
- Website: <https://kommandhub.com>
- Support: <https://kommandhub.com/support>
