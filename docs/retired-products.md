# Retired products

Decision date: 2026-10-09. Owner decision: **DAIYING_NOVEL = RETIRED**, **DAIYING_VIDEO = RETIRED**.

`daiying_novel`, `official.novel-collector`, `daiying-video` and `official.video-collector` are removed from active product planning, maintenance tasks, new installer distributions and mandatory Core release acceptance. No new features or compatibility development is planned.

Historical plugin source remains at `content/plugins/official.novel-collector` and `content/plugins/official.video-collector`; both themes were already absent from the current upstream tree. Historical product-only tests remain in `tests/retired/` for archival use, outside the active top-level Core regression suite. Their relative paths were adjusted only to preserve standalone historical execution.

The shared `theme_productization_contract.php` covered only the now-retired novel/video products; its remaining video assertions and the video-only smart-mode test are archived in `tests/retired/`, with historical bodies retained. Active shared theme/market/plugin tests remain unchanged. `tests/retired_products_distribution.php` validates distribution exclusions and manifests for supported default, media and safe themes. Generic fixture identifiers do not imply maintenance of retired products. Other failures remain real release blockers.

Legacy official plugin registry trust, capability/table namespaces and frozen migrations remain unchanged to protect already-installed customer sites. Retirement does not disable or uninstall existing copies, remove stored content, revoke licenses or alter paid orders. Historical releases and source history are retained.

The installer builders and release parity expectations exclude only these four product paths. Core update packages do not delete customer plugin/theme directories. No marketplace delisting, product deletion or license revocation is authorized. Live marketplace/customer records have not been verified; any future marketplace operation requires separate review and Owner approval.
