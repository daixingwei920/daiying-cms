# Retired products

Decision date: 2026-10-09. Owner decision: **DAIYING_NOVEL = RETIRED**.

Both `daiying_novel` and `official.novel-collector` are removed from active product planning, maintenance tasks, new installer distributions and mandatory Core release acceptance. No new features or compatibility development is planned.

Historical plugin source remains at `content/plugins/official.novel-collector`; the theme was already absent from the current upstream tree. Historical product-only tests remain in `tests/retired/` for archival use, outside the active top-level Core regression suite. Their relative paths were adjusted only to preserve standalone historical execution.

The video product assertions in `tests/theme_productization_contract.php` remain unchanged. Generic theme asset and market integrity tests retain their historical novel identifiers as fixtures: they test shared infrastructure, not product readiness. A missing video product or another failing test remains a real release blocker.

Legacy official plugin registry trust, capability/table namespaces and frozen migrations remain unchanged to protect already-installed customer sites. Retirement does not disable or uninstall existing copies, remove stored content, revoke licenses or alter paid orders. Historical releases and source history are retained.

The installer builders and release parity expectations exclude only these two product paths. Core update packages do not delete customer plugin/theme directories. No marketplace delisting, product deletion or license revocation is authorized. Live marketplace/customer records have not been verified; any future marketplace operation requires separate review and Owner approval.
