# Daiying CMS Plugin Decoupling Regression Report

Date: 2026-09-10
Baseline: Daiying CMS Core 1.2.48, commit 41a03ab

## Commands Run

```bash
php tests/market_plugin_migrations.php
php tests/plugin_decoupling_foundation.php
php tests/frozen_migration_checksums.php
php tests/commerce_v1_contract.php
php tests/official_mail_contract.php
php tests/official_affiliate_hub_contract.php
php tests/market_review_submission_payment_provider.php
php -l tests/market_plugin_migrations.php
php -l system/core/Plugin/OfficialPluginRegistry.php
php -l system/core/Plugin/OfficialExtensionTrustGrantRepository.php
php -l system/core/Market/MarketPackageInstaller.php
```

## Results

All listed tests passed.

## Covered Regression Cases

- Market install runs plugin migrations.
- Market install records plugin migration checksums.
- Market upgrade runs newly declared migrations.
- Migration checksum changes are rejected.
- Failed migrations roll back installation state.
- Failed official installs remove the staged Trust Grant.
- `official.commerce` can use the granted `commerce_` prefix.
- `official.affiliate-hub` can use the granted `affiliate_` prefix.
- `official.mail` can create `mail_oauth_configs`, `mail_accounts`, and `mail_messages`.
- Enable-time migration backfill runs before enabling a market plugin.
- Tampered signed grant is rejected.
- Revoked signed grant is rejected.
- Expired signed grant is rejected.
- Grant cannot authorize migrations outside its table prefix.
- Unregistered official-like package cannot use a reserved official prefix.
- Plugin admin menu metadata renders without hard-coded plugin ids.
- Payment provider redirect policy extension point works.
- Payment provider settings schema renders public and secret fields.
- Frozen migration checksums are preserved.
- Commerce, Mail, Affiliate Hub, and payment-provider review contract tests remain green.

## Not Performed

- No production site changes.
- No official update server publication.
- No live external payment or mail API calls.
- No GitHub push.

## Conclusion

The P0 official plugin decoupling foundation is ready for review. It removes the need to modify Core static registries for normal new official plugins, while preserving reserved-prefix and trusted-code protections.

