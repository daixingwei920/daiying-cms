# Core Foundation Freeze Checklist

## Foundation Areas

- [x] Release parity gate
- [x] Public API version registry
- [x] Plugin API v1 documentation
- [x] Theme API v1
- [x] Storage Provider API v1
- [x] REST API v1 foundation
- [x] Global AI configuration and adapters
- [x] Mail infrastructure
- [x] Role/capability service
- [x] Queue service
- [x] Scheduler service
- [x] Cache service
- [x] Webhook service
- [x] Secret redaction
- [x] Log rotation
- [x] System health checks
- [x] Content revision/autosave/trash safety
- [x] Boundary report for Distribution, Commerce, Marketplace, Update Server, and private infrastructure

## Freeze Blockers

- [x] Fresh `1.2.29` installer HTTP smoke with SQLite, `/health`, admin login page, and readiness JSON
- [x] Tagged `v1.2.19` empty-site local upgrade smoke to current Foundation files
- [x] Tagged `v1.2.24` empty-site local upgrade smoke to current Foundation files
- [x] Tagged `v1.2.19` data upgrade smoke with content, taxonomy, media, plugin state, and payment settings
- [x] Tagged `v1.2.24` data upgrade smoke with content, taxonomy, media, plugin state, and payment settings
- [ ] Full package/browser upgrade from `1.2.0` empty site to current
- [ ] Full package/browser upgrade from `1.2.0` content/media site to current
- [ ] Full package/browser upgrade from `1.2.19` plugin/theme site to current
- [ ] Full package/browser upgrade from `1.2.22` payment-configured site to current
- [ ] Full package/browser upgrade from `1.2.24` Commerce data site to current
- [ ] Upgrade with existing AI and mail secrets preserved
- [ ] Failed update rollback with AI/mail/payment secrets preserved
- [ ] REST/media/admin browser smoke after packaged upgrade
- [ ] Package artifact SHA/signature/manifest parity for the final RC

## Rule For Freeze

Core Foundation Freeze may be announced only after every freeze blocker above is
checked off with a reproducible test log and exact commit hash.
