# Distribution

Distribution is a key Daiying CMS direction, but the public repository currently shows it as an evolving product layer rather than a stable API.

## Status

**Development / Preview**

No stable `DistributionProvider` interface or final Distribution user guide is currently present in the public repository. Documentation should avoid calling Distribution stable until those files exist and the related development thread promotes the feature.

## Intended Problem Space

Distribution is meant to support workflows such as:

- Packaging CMS capabilities for delivery.
- Distributing official and third-party extensions.
- Connecting commercial licensing to downloads and updates.
- Supporting one-site or multi-site authorization models.
- Preparing deployable site capability bundles.

## Confirmed Foundations In The Current Repository

The repository already contains several foundations that can support Distribution:

- Market package client and installer classes under `system/core/Market`.
- Extension dependency, conflict, update, rollback, and job infrastructure.
- Commercial product, license, license-site, download-token, and audit-event schema.
- Card-code delivery.
- Payment Provider foundation.
- Signed Core update flow.
- Capability pack and site vault schema.
- Shadow upgrade schema.

## Relationship To Commerce

Commerce handles payment, purchase, entitlement, license, and delivery records. Distribution should build on that layer when a purchased or authorized package needs to be delivered, updated, or validated for a site.

## Documentation Gap

The following stable docs are still needed before Distribution can be advertised as production-ready:

- Distribution user workflow.
- Distribution Provider interface.
- Package schema.
- Authorization and license validation rules.
- Release/update flow for distributed extensions.
- Admin UI screenshots.
- Security model and threat checklist.
