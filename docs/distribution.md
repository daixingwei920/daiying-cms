# Distribution

Distribution is the Daiying CMS layer for sending prepared content and commerce data to supported external channels.

## Status

**In Development**

No dedicated stable `system/core/Distribution` module, final Provider/Connector interface, channel list, or user workflow is currently present in this public repository branch. Documentation should stay conservative until the current implementation is merged.

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

The final boundary should follow the merged implementation once it is available.

## AI Role

If AI support is implemented, it should be treated as an adapter aid, not as a separate fallback system. AI may help with:

- Title and description adaptation.
- Field conversion.
- Content cleanup and formatting.
- Platform-specific format adaptation.
- Basic data suitability checks.

If an AI service, Provider, or channel fails, the user-facing workflow should report the current error state clearly. It should not invent alternate distribution behavior that is not implemented.

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
