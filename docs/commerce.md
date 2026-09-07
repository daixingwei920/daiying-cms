# Commerce

Commerce is currently a Core foundation plus plugin extension area.

## Current Status

**Foundation / Beta**

The current public repository contains:

- Official Commerce plugin foundation under `content/plugins/official.commerce`.
- Payment Provider settings and registry.
- Manual payment provider.
- Hosted redirect payment provider foundation.
- Payment attempts and diagnostics.
- Paid content access.
- Paid download access.
- Card-code product inventory and delivery.
- Commercial product and license storage tables.
- Site license activation storage.
- Alpha Distribution channel management for commerce data.

## Payment Providers

Core includes the Provider interfaces and registry under `system/core/Payment`. Provider settings are managed in the admin backend, and payment diagnostics can be checked with:

```sh
php scripts/diagnose_payment_providers.php --json
```

## Card Delivery

Card-code products can be sold through content blocks and fulfilled idempotently after payment confirmation.

## Commercial Licenses

The commercial ecosystem schema contains tables for products, license batches, license keys, license sites, download tokens, and audit events.

## Distribution Boundary

Commerce handles product, price, order, payment, and fulfillment data. Distribution handles sending prepared product or content data to external channels. The current alpha implementation lives inside the official Commerce plugin, but Distribution should not be described as plugin licensing, marketplace delivery, or Core updates.

## Scope Notes

Full storefront, tax, settlement, and unrelated dropshipping workflows are not documented as stable public Core features in this repository.
