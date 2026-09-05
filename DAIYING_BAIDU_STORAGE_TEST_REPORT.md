# Daiying CMS Baidu Storage Test Report

Date: 2026-09-05

## Automated Tests

Command results:

- `php -l content/plugins/official.storage.baidu/src/BaiduOAuthService.php` passed.
- `php -l tests/baidu_storage_provider_contract.php` passed.
- `php tests/baidu_storage_provider_contract.php` passed.
- `php tests/market_review_submission_payment_provider.php` passed.
- `php tests/market_theme_hyphen_extension_id.php` passed.
- `php tests/novel_collector_frontend_contract.php` passed.

## Covered Behaviors

- OAuth authorize URL uses official Baidu HTTPS host.
- Authorization URL includes App Key but does not expose Secret Key.
- OAuth state format and single-use behavior are enforced.
- Callback URL derives from current host and preserves CMS subdirectory base path.
- Callback stores access token through encrypted secret storage and marks connection as connected.
- Directory list and search map Baidu files/folders into CMS `MediaProviderItem`.
- Remote media resolve URL returns a controlled CMS route and does not expose `access_token`.
- Download URL validator allows expected Baidu hosts and rejects HTTP, loopback IP, foreign hosts, and non-443 ports.
- Plugin public data does not contain the Secret Key.
- Media picker exposes generic remote providers and no longer contains the hard-coded Cloudreve source check covered by the test.

## Not Covered

- Real Baidu OAuth callback with production credentials.
- Live Baidu Netdisk list/search/download latency and quota behavior.
- Large media streaming beyond the RC 50 MB proxy cap.

