# Daiying CMS Foundation Boundary Report

Scope: public Daiying CMS Core repository only. No production site changes and no
official update-server publishing were performed.

## Current Boundary

### Public CMS Core

Allowed in the public CMS package:

- Core update client: `Cms\Core\Update`
- Site-side market client and package installer: `Cms\Core\Market`
- Site-side extension install logs and jobs:
  - `cms_market_install_logs`
  - `cms_market_jobs`
  - `cms_market_job_audits`
- Site-side review submission tracking:
  - `cms_review_submissions`
- Site AI configuration:
  - `cms_ai_settings`

These are used by installed customer sites to check updates, install reviewed
packages, track local install state, and submit extension packages for review.

### Private Official Infrastructure

Must remain private infrastructure and must not be coupled to site-level Core AI:

- Official update server implementation
- Official market server implementation
- Official AI Review provider runtime
- Official market billing, settlement, developer portal and license server state
- Official update/package signing private keys

Historical public migrations still contain `cms_market_*` server-side table names
from earlier development. They must not be deleted in a Foundation minor release,
because removing historical migrations would break cross-version upgrades and
migration history. They are classified as migration debt to split in a future
major or installer profile cleanup.

## AI Boundary

Site AI and official market AI Review are separate systems.

Site AI:

- Uses `Cms\Core\Ai`
- Stores site configuration in `cms_ai_settings`
- Is called by CMS features and installed site plugins through public Core AI API

Official market AI Review:

- Must use its own Provider configuration
- Must not read `cms_ai_settings`
- Must not call `Cms\Core\Ai\AI::forSite()`
- Must not share API Keys or database configuration with installed sites

## Commerce / Payment Boundary

Core may keep:

- payment provider interfaces
- safe checkout URL validation
- payment ledger primitives
- webhook verification primitives
- card delivery only where it is intentionally a general Core entitlement
  primitive

Commerce plugin owns:

- products
- orders
- inventory
- logistics
- fulfillment workflows
- product AI prompts

Payment providers such as Stripe, PayPal, WeChat Pay and Alipay remain plugins.

## Distribution Boundary

Distribution remains external content/product channel distribution.

It is not:

- plugin marketplace
- license delivery
- update system
- capability pack deployment
- commercial plugin packaging

Distribution-specific providers and workflows remain outside Core unless they are
pure public infrastructure contracts.

## Package Boundary

Public release builders must not include private update-server source trees or
secrets. The full installer builder now rejects both:

- `updates.daiyinggame.com/`
- `updates.daiyingcms.com/`

Existing secret exclusions for `.env`, private keys, runtime databases, logs,
sessions and cache directories remain in place.

## Current Debt

| Area | Status | Action |
| --- | --- | --- |
| `2026_08_12_000007_market_server_schema.php` | Historical migration includes private-market server tables. | Keep for migration compatibility now; plan future public/private installer profile split. |
| `2026_08_23_000002_market_ai_review_schema.php` | Historical migration includes market AI review tables. | Keep for migration compatibility now; prevent runtime dependency on site AI. |
| `Cms\Core\Market\DatabaseMarketJobRepository` | Site-side market job queue exists separately from Foundation Queue. | Keep for compatibility; future minor may bridge to Foundation Queue additively. |
| `cms_review_submissions` | Public site-side submission tracking. | Keep. |
| `cms_market_install_logs` | Public site-side extension install history. | Keep. |

## Automated Guard

`tests/foundation_boundary_contract.php` verifies:

- public release builder rejects old and current update-server directory names
- site-level AI code does not reference `cms_market_ai*`
- market AI Review migrations do not reference `cms_ai_settings`
- official update-server AI Review isolation remains documented in `docs/ai.md`
- current official public URLs remain `www.daiyingcms.com` and
  `updates.daiyingcms.com`

## Foundation Freeze Status

Boundary is documented and guarded, but historical private-market migrations are
still present in public Core for compatibility. This is acceptable for a
Foundation Candidate only if the release notes clearly classify them as legacy
migration debt and no runtime dependency exists between installed-site AI and
official market AI Review.
