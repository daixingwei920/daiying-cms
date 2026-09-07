# Distribution

Distribution is the Daiying CMS layer for sending prepared content and commerce data to supported external channels.

## Status

**In Development**

The current public repository contains an alpha Commerce Distribution implementation under `content/plugins/official.commerce`.

Confirmed implementation points in the current branch:

- Admin routes are registered by `content/plugins/official.commerce/plugin.php`.
- Distribution management is handled by `CommerceDistributionManager`.
- Current data tables are created by `migrations/006_distribution_modules.php`.
- Current event history is stored through the Commerce repository.
- Current provider classes include system share and Google Merchant provider implementations.

There is still no dedicated stable `system/core/Distribution` module or final standalone Provider/Connector specification. Public documentation should continue to label Distribution as In Development until the workflow, channel contract, and compatibility rules are finalized.

## Intended Problem Space

Distribution is meant to support workflows such as:

- Taking content or product information created once inside Daiying CMS.
- Collecting related images, video, audio, files, and metadata.
- Selecting or matching supported external channels.
- Adapting fields, titles, descriptions, formats, and media requirements for each channel.
- Calling the channel Provider or Connector.
- Returning clear success, failure, channel error, or AI error status.

## Conceptual Flow

```text
Content / product creation
-> media resources
-> Distribution
-> external channel selection or matching
-> AI / rule adaptation
-> Provider / Connector
-> external delivery
-> status feedback
```

## What Distribution Is Not

Distribution is not:

- The Daiying CMS plugin marketplace.
- Commercial plugin licensing.
- Authorization-code delivery.
- The CMS update system.
- Capability Pack.
- Site Vault.
- Shadow Upgrade.
- A mechanism for packaging and deploying plugins to a site.

Those capabilities may exist elsewhere in Daiying CMS, but they should be documented under Commerce, Marketplace, Update System, or Architecture rather than being used as the Distribution product definition.

## Relationship To Commerce

Commerce handles products, pricing, orders, payment, and transactions.

Distribution handles sending already prepared content or product data to external channels. For example, Commerce may provide product and transaction capabilities, while Distribution sends appropriate product data to a supported external channel.

The current merged implementation places Distribution inside the official Commerce plugin. The long-term boundary should continue to follow the real architecture as it stabilizes.

## AI Role

If AI support is implemented, it should be treated as an adapter aid, not as a separate fallback system. AI may help with:

- Title and description adaptation.
- Field conversion.
- Content cleanup and formatting.
- Platform-specific format adaptation.
- Basic data suitability checks.

If an AI service, Provider, or channel fails, the user-facing workflow should report the current error state clearly. It should not invent alternate distribution behavior that is not implemented.

The current Commerce plugin also contains AI module infrastructure. Distribution documentation should only describe AI behavior that is visible in the merged implementation and should not promise channel-specific automation that has not shipped.

## Documentation Gap

The following stable docs are still needed before Distribution can be advertised as production-ready:

- Distribution user workflow.
- External channel Provider/Connector interface.
- Supported channel list.
- Channel data schema and mapping rules.
- AI/rule adaptation behavior.
- Error and retry behavior.
- Admin UI screenshots.
- Security model and threat checklist.
