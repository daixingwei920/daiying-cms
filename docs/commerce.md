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
- Product-description drafting through the site-level Core AI service.
- Site license activation storage.
- Alpha Distribution channel management for commerce data.

## Payment Providers

Core includes the Provider interfaces and registry under `system/core/Payment`. Provider settings are managed in the admin backend, and payment diagnostics can be checked with:

```sh
php scripts/diagnose_payment_providers.php --json
```

Payment plugins should declare admin settings through the public Provider Settings Schema and checkout redirect allow rules through the Redirect Policy interface. See [Payment Provider Development](payment-providers.md).

## Card Delivery

Card-code products can be sold through content blocks and fulfilled idempotently after payment confirmation.

## Commercial Licenses

The commercial ecosystem schema contains tables for products, license batches, license keys, license sites, download tokens, and audit events.

## Product Description AI

The bundled Commerce plugin can inherit the Core site AI configuration and generate a product-description draft from fields the administrator has already entered, including product name, price, transaction region, category/context fields, brand/model, specifications, selling points, and short notes.

The generated text is returned to the product description editor and saved through the normal CMS content pipeline when the product is saved. This keeps Commerce product text on the same long-term content storage path as other CMS content.

The first workflow does not fetch or scrape external product URLs. If a source URL is entered, it is treated only as administrator-provided context for the prompt and as a future extension point.

## Distribution Boundary

Commerce handles product, price, order, payment, and fulfillment data. Distribution handles sending prepared product or content data to external channels. The current alpha implementation lives inside the official Commerce plugin, but Distribution should not be described as plugin licensing, marketplace delivery, or Core updates.

## Scope Notes

Full storefront, tax, settlement, and unrelated dropshipping workflows are not documented as stable public Core features in this repository.
