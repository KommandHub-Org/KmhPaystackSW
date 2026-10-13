# 0.9.0-beta.3

Third pre-release. Still for internal development, QA and sandbox/staging
testing; not yet submitted to the Shopware Store.

**Upgrade note: the plugin was renamed.** Its technical name changed from `KommandhubPaystackSW` to `KmhPaystackSW`, so Shopware treats it as a different plugin and `0.9.0-beta.1` / `0.9.0-beta.2` installs are not updated in place. Install `KmhPaystackSW`, re-enter your Paystack API keys and settings (they now live under the `KmhPaystackSW.config.*` domain), and then remove the old plugin. The custom field set and the bank fields were renamed as well (`kmh_paystack_*`); bank details saved by earlier versions are not migrated.

- Fresh installs are now ready to use: the Paystack payment method is assigned to all sales channels when it is first created, and Sandbox Mode is on by default, so a new install never calls live Paystack until it is switched off. Updating the plugin never re-adds the method to a channel it was removed from.
- A declined card is now recorded as a failed payment instead of a cancelled one. When Paystack sends the customer back without a reference (cancel/back, some failures), the plugin verifies the reference stored at checkout and lets Paystack's real status decide: failed payments fail, abandoned ones are cancelled, successful ones are marked paid, and pending ones stay open.
- A late `charge.success` webhook for an earlier payment attempt is now matched to its order. Every retry overwrites the stored reference, so the order transaction id is also sent in the Paystack metadata and used as a fallback lookup; the payment is still verified with Paystack before the order is marked paid.
- Documentation: first-install behaviour, the requirement that the checkout currency is enabled on your Paystack account, and troubleshooting for sandbox "Insufficient Funds" on large amounts and for Shopware's default status-email wording.

# 0.9.0-beta.2

Second pre-release. Still for internal development, QA and sandbox/staging
testing; not yet submitted to the Shopware Store.

- Debug logging is now resolved per sales channel. `enableDebugging` and the log levels are ordinary plugin settings, so a merchant can scope them to a single sales channel; previously they were only read from the global scope, so enabling debugging on one sales channel produced no output at all.
- Storefront bank-verification template blocks are namespaced, so they no longer collide with other plugins that extend the same account templates.
- Relicensed from MIT to the Apache License 2.0, adding an explicit patent grant, an explicit reservation of trademark rights, and a `NOTICE` file that carries attribution into forks.
- The plugin now states clearly that it is an independent, third-party integration and is not affiliated with or endorsed by Paystack. Added a trademark and branding policy (`TRADEMARKS.md`).
- Replaced the third-party logos used as the plugin and administration icons with KommandHub branding.
- Build tooling: `make prepare` now works for pre-release versions and on repeat runs, and the test container ships `shopware-cli` for plugin validation.

---

# 0.9.0-beta.1

Pre-release for internal development, QA and sandbox/staging testing. Not yet
submitted to the Shopware Store. The public API and namespaces may still
change before `1.0.0`.

- Paystack payment for Shopware 6: card, bank transfer, USSD and mobile money.
- Payment verification checks status, amount and currency before an order is marked paid.
- Refunds from the order detail page, including partial refunds, with a server-side over-refund guard.
- Dedicated "Paystack refund" admin permission that can be assigned to roles (depends on the order editor permission).
- Webhook handling for `charge.success`, `refund.pending` and `refund.processed`, with signature verification.
- Bank-account verification in the customer account (account resolution via Paystack).
- Correct amount handling for every currency Paystack supports, including zero- and three-decimal currencies (e.g. XOF, RWF, KWD). The plugin does not create currencies or languages in the shop.
- Plugin interface translated into English, German and French.
- Configurable logging, sandbox/live mode and a minimum refund amount.
- Supports Shopware 6.6 and 6.7.
