# Daiying CMS Core Agent Rules

This file is long-term project context for Codex agents working on Daiying CMS Core.

Update this file whenever the official Core baseline, clean worktree, release process, or maintenance boundary changes.

## Owner-authorized request-driven AI execution (2026-10-19)

- Owner chose “先实现免 Worker，再发布”: implement a minimal public Core execution contract so the Logo plugin can execute a member-owned durable task directly after submission, without site Cron configuration.
- Clean worktree `work/core-direct-ai`, branch `feat/core-ai-direct-execution`, base `4acf2d8` from current remote main. Plugin changes remain in the separate `work/logo-direct-execution` checkout.
- Development package identity: `1.2.88-dev.1`, unpublished; do not overwrite signed 1.2.87 archives or advertise this as Stable.
- Preserve existing asynchronous submission/CLI contracts, site AI credentials and configured Timeout. Never replay uncertain provider dispatches; retain media, quota, audit and idempotency rules.
- Isolated development and tests are authorized. Existing production Workers, payment configuration and refunds remain untouched. This does not authorize a new Core Stable publication or production upgrade; prepare a verified candidate before seeking that approval.

## Owner-approved Core 1.2.87 publication (2026-10-19)

- Owner explicitly approved publication to GitHub and the official update service after formal signing/Gate acceptance. Both are now published and verified as 1.2.87 at tag `v1.2.87`, commit `a5031178b4b30d1bf50c289953ac9a76a0c3832b`.
- GitHub public attachments and official update downloads match the frozen signed artifacts. Do not rewrite the tag, signature or published archives.
- The official website was not upgraded: before/after health remains Core 1.2.86, NORMAL, maintenance=false. Payment settings were not changed; no payment or refund requests were made.
- Earlier publication holds below are historical and superseded for this release only. Production website upgrades, other products/market releases and future releases still require explicit Owner authorization.
- See `docs/CORE_1.2.87_PUBLICATION_REPORT.md`. This publication-record commit does not alter the released runtime or rebuild its signed artifacts.

## Owner-authorized main merge and formal signing (2026-10-19)

- Owner authorized merging the verified 1.2.87 candidate into main, formal server-side signing and Release Gate acceptance. Publication remains on hold.
- Accepted runtime source: `fe4a8843a5132d0162cde3f1d09f2902d563ef68`; matching installer/update parity and isolated SQLite/MySQL upgrade, retention, rollback and retry acceptance passed.
- Freeze the merged main commit for formal artifacts. Use the existing official signer only on the update server; do not export private keys or change the signing trust root.
- Do not create a release tag, GitHub Release, public update-service entry or production upgrade. Production payment settings and refunds remain prohibited. Earlier no-formal-signing notes below are superseded only for this explicit preparation task.

## Owner-authorized navigation takeover (2026-10-19)

- Owner asked this chat to take over the side-panel navigation repair: generic admin-configured grouping, parent selection and ordering, compatible with existing themes and saved menus.
- Clean takeover worktree `work/core-navigation-takeover`, branch `fix/navigation-groups-takeover-1.2.87`, based on `4c117437834a699be47cb91e963592fa27020d7a`. Earlier uncommitted worktree and official-theme snapshot are preserved.
- Official theme is a separate 1.0.7 candidate, never part of the Core payload. Preserve all 11 live navigation destinations. Isolated development/tests only; no production data changes, signing/publication, payment changes or refunds.

## Owner publication hold and article-save repair (2026-10-18)

- Latest direct Owner instruction: fix and test the article #41 save failure; do not publish CMS. This overrides prior release authorization below.
- Isolated repair branch: `fix/admin-block-utf8`, clean worktree `work/core-article41-utf8`, base `4a553fa` from the unpublished 1.2.87 candidate.
- No formal signing, release tags, GitHub/update-service publication, production upgrades, production database writes, payment-setting changes or refunds.
- Owner subsequently authorized clearing all three readiness blockers, rebuilding candidate packages, and completing real image and Stripe Test payment acceptance. Article repair is merged into `maintenance/release-1.2.87`; only isolated instances and temporary server-side test signing are permitted. CMS publication remains on hold.

## Earlier 1.2.87 release candidate scope (publication on hold)

- Owner requests 1.2.87 instead of the unpublished 1.3.0 development version; resolve remaining release blockers, test, then sign and publish Core, Stripe fix and Logo plugin.
- Clean release worktree: `work/core-1.2.87-final`, branch `maintenance/release-1.2.87`, based on main 42ca4ffb3a44.
- Published baseline remains 1.2.86 until verified publication. Production website runtime and collection settings must remain unchanged; real refunds are prohibited.

## Current Core Baseline

- Product: Daiying CMS Core.
- Published baseline: `1.2.87`, official tag `v1.2.87`, frozen commit `a5031178b4b30d1bf50c289953ac9a76a0c3832b`; GitHub Latest, official update service and actual downloads verified 2026-10-19.
- Frozen clean signing clone: `/root/daiying-core-signing-1.2.87-20261019-NO3kDH/work`. Release source is main merged through PR #59; runtime matches the accepted `fe4a884` candidate. Documentation-only publication records follow separately.
- Released scope: shared image AI/private media/quota/audit, atomic plugin documents, scoped workers and branding contracts; article UTF-8 save, payment callback/checkout reliability and generic navigation grouping. Preserve text AI, configured Timeout, published migrations and existing public interfaces.
- Publication evidence: `docs/CORE_1.2.87_PUBLICATION_REPORT.md`. Official website runtime remains 1.2.86 / NORMAL; publication did not upgrade it or change payment settings.
- Logo/Stripe plugins and official theme remain separate packages; their publication is not implied by the Core release. Historical Stable 1.2.86 lacked image AI; Stable 1.2.87 includes the generic contracts.
- All existing clean-source, signing-key, approval, maintenance and production boundaries below remain in force.

## Owner-authorized payment callback repair (2026-10-18)

- Current clean branch: `fix/payment-refund-webhook-idempotency`, worktree `/Users/xingweidai/Documents/Codex/2026-10-17/cns-cms/work/core-payment-callback-fix`, base `e2643fa516b2f42c0d9cd7de3f9104b5f95503f5` (PR #55 merged).
- Development version `1.3.0-alpha.5`; not a Stable release or production deployment.
- Repair remote refund identity deduplication and pending callback settlement in Core. Stripe mapping is a separate payment-provider source/package, never part of Core payloads.
- No production writes, real payment/refund creation, formal signing or publication. Logo plugin proactive refunds remain disabled. Synthetic signed callbacks and local ledger fixtures are authorized for this repair.

## Owner-authorized AI image development (2026-10-18)

- Development branch: `codex/core-logo-integration-1.2.86`, clean base `6cdeee4c0ff67d2a0643317915b2331cd1638ed1` (published 1.2.86 plus publication documents).
- Development version: `1.3.0-alpha.4`, not Stable and not approved for publication.
- Current isolated branch: `codex/core-logo-integration-1.2.86`, worktree `/Users/xingweidai/Documents/Codex/2026-10-17/cns-cms/work/core-logo-integration-1.2.86`; merges all generic image AI, atomic document, metering and branding contracts from b5e9d52 with the published 1.2.86 baseline. Stable 1.2.86 remains a separate frozen release.
- Workspace: `/Users/xingweidai/Documents/Codex/2026-10-17/cns-cms/work/core-logo-integration-1.2.86`.
- Separate plugin source: sibling `daiying-ai-logo`; never include it in Core-owned payloads.
- Owner explicitly authorized the generic image AI contract, adapter, queue, private media, quota and audit work despite the default maintenance-only rule.
- Stable 1.2.85 and 1.2.86 artifacts are published and frozen. Do not rewrite their commit/tag/artifacts.
- No production writes; existing site AI settings may be read for explicitly requested isolated live tests. Never copy keys to the workstation or into output/logs.
- Other thread owns LocalPluginPackageInstaller market API enable repair; integrate only its immutable tested commit, not shared dirty files.

## Workspace Rules

Use the clean Core worktree above, or create a fresh clean clone/worktree from the current official Core branch, for all Core maintenance.

Do not use this legacy/WIP worktree as a Core release source:

`/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/work/daiying-cms`

That legacy directory may contain Baidu Netdisk WIP, AI Customer Service WIP, historical reports, old outputs, or other plugin/business work. It is not a clean Core release source.

## Core Maintenance Mode

Daiying CMS Core is in maintenance mode.

Allowed Core work:

- Security fixes.
- Critical bug fixes.
- Compatibility fixes.
- Upgrade/update reliability fixes.
- Release Gate fixes.
- Runtime/version consistency fixes.
- Minimal maintenance needed to keep existing Core public APIs stable.

Not allowed without an explicit new product decision:

- Large new Core features.
- New business modules.
- Plugin-specific special cases.
- Theme-specific behavior.
- Marketplace/business logic that belongs in plugins.
- Refactors unrelated to security, reliability, compatibility, or upgrade correctness.

## Plugin / Business Boundary

The following must not be mixed into Core commits, Core release packages, or Core update packages:

- Restaurant plugin/theme work.
- CJ / CJ Dropshipping plugin work.
- Baidu Netdisk plugin remediation.
- AI Customer Service plugin work.
- Commerce feature work that belongs inside `official.commerce`.
- Payment provider feature work that belongs inside payment plugins.
- Any WIP plugin directory or plugin-specific local test artifact.

If a plugin appears to require a Core change, first verify whether the missing capability is truly a generic public Core API. Do not add plugin ID hard-coding or one-off plugin special cases to Core.

## Release Source Rules

Never build a Core release package from:

- `git diff`
- only the files changed in the current task
- Codex's guess of "related files"
- stale local `outputs/`
- old update package directories
- dirty worktrees
- plugin-market worktrees
- legacy/WIP worktrees

Every official Core release must be built from a complete, deterministic, Core-owned release snapshot and must pass Release Gate checks before publication.

## Required Checks Before Core Commit or Release

For any Core modification, run at minimum:

- `git status --short`
- `git diff --check`
- PHP syntax checks for changed PHP files, or the relevant stable syntax target.
- `php tests/release_gate_v1_contract.php`
- Related regression tests for the modified subsystem.

For release or update-package work, also run the current Release Gate / release parity process and verify:

- Full Core-owned file manifest.
- `release_gate_v1` is declared for modern Core update packages.
- Package SHA256 matches metadata.
- Update package and GitHub Release come from the same release source.
- No future-version or WIP files are mixed in.
- Rollback and active-release bootstrap behavior remain intact.

## Forbidden Release Contents

Do not include any of the following in Core commits or release packages:

- Old `outputs/` artifacts.
- Local signing inputs.
- Test private keys.
- Local RSA/Ed25519 private keys.
- `.env` files.
- Production credentials.
- OAuth secrets.
- Payment secrets.
- Session files.
- Logs.
- Cache files.
- WIP plugin files.
- Historical archive directories.
- Temporary install smoke directories.

The known archived local test private key under the closeout archive is for local historical testing only and must never be committed or published.

Owner signing boundary: signing private keys may only be generated and used on
the update server. Do not generate, read, copy or export signing private keys
on the local workstation, including temporary asymmetric test signing keys.
Tests that require key generation must run on the authorized update server;
record them as not run locally. Local candidate artifacts remain unsigned.

## Version Updates

When the official Core version changes:

1. Update this `AGENTS.md` baseline section.
2. Update `config/app.php` and `config/app.example.php` only through the approved release process.
3. Update `CHANGELOG.md`.
4. Ensure `system/core-manifest.json` matches the released Core snapshot.
5. Ensure Git tag, GitHub Release, update server metadata, and website download/version displays agree.

## Stable Public API Rule

Core public APIs are long-lived. Do not break plugin/theme public APIs in a patch release.

If a Core API must evolve:

- Preserve backward compatibility when possible.
- Add compatibility layers for existing plugins/themes.
- Document deprecations before removal.
- Add regression tests.

## Default Agent Behavior

At the start of a Daiying CMS Core task:

1. Read this file.
2. Confirm the worktree is the clean Core worktree or a fresh clean clone.
3. Run `git status --short`.
4. Check the current Core version and Git commit.
5. Refuse to publish from a dirty or legacy/WIP worktree.
6. Keep plugin/business development in independent plugin threads.

## Retired Product Boundary

Owner decision (2026-10-09): `DAIYING_NOVEL = RETIRED`, `DAIYING_VIDEO = RETIRED`.
`daiying_novel`, `official.novel-collector`, `daiying-video` and
`official.video-collector` are no longer maintained,
planned products, new installer components, or mandatory Core acceptance targets.
Preserve historical source, frozen migrations, installed customer assets,
trust records, orders and licenses. Do not revive this product as a Core task.
Do not remove assertions for other products to make the release gate pass.
See `docs/retired-products.md`.

## 长期知识库维护规则

代码与知识库必须同步维护。本节长期适用于参与戴影 CMS 开发的所有 Codex 会话；不改变已批准的架构契约、Core 边界、Owner 决策、发布审批或生产 fail-closed 要求。不得因知识同步擅自修改这些约束。

- 统一产品事实源为根目录 `DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1.json`；配套 `.md`、`.user-facing.json` 和架构文档从 `docs/README.md` 进入。沿用 V1 标识，不另建平行知识库。
- 每次新增功能、改代码、修 Bug、调整 API、数据库结构、插件或主题规范，先检查相关知识、教程、兼容性与版本说明并同步更新；不受影响时记录不更新的具体理由。不得改写已发布迁移或批准契约来迁就文档。
- 每次开发任务结束前，对照实际 diff、版本与来源逐项核对知识一致性。修改知识包后运行 `python3 scripts/product_knowledge.py --write`，再运行不带参数的检查；这只更新和检查文档派生物，不部署、不重建索引。
- 每次正式发布，同步版本记录、使用教程、兼容性说明和必要技术文档。按实际已批准的发布版本记录来源完整 commit、路径或 URL 与验证时间；候选版本、提交成功和安装成功均不能代替正式发布或整体验收。
- 每周至少一次知识一致性检查，在 `docs/knowledge/` 记录检查日期、来源版本、发现的问题、处理状态和遗留事项。自动调度尚未实施时，下一次开发会话先核对本周记录，补查或报告逾期，不能声称已启用定时任务。
- 严格区分已实现、已测试、待验证与规划。仅源码/文档复核不得标记运行测试 PASS；Git 提交、路径名或旧报告不能自动把功能晋级为已验收。测试证据必须记录实际环境与范围。
- 检索要求为 P0 官方知识、P1 已审核已发布文章、P2 普通已发布文章；Draft/Pending/C/D、内部资料及状态未知内容默认禁用。待优化原文不因可作为输入而成为可信参考知识。
- USER-FACING 与 INTERNAL/ADMIN_DEVELOPER 必须分开；普通客服只用 USER-FACING 导出，未知事实明确无法确认。不得向客服暴露内部资料，也不得把类名或方法改写成用户按钮。戴小影客服继续使用站点配置的 gpt-4.1-mini，模型调用复用 Core AiService/AiGateway。
- 每份交付报告必须包含「知识库同步状态」，列明更新文件、未更新原因、遗留事项，以及构建/导入/索引/消费者应用各自的真实状态。未部署同步服务不得写成已实时同步。
- Git 只纳管审核过的事实、来源、规范和独立工具；禁止提交运行索引、向量缓存、对话、生产备份或密钥。现有索引不得为同步而擅自清空或重建。

## Owner-authorized isolated integration (2026-10-14)

- Baseline: clean `f1388e828a3dfa81698dbdfd66351ddb9e0699f3`, Core 1.2.84.
- Source branches: frozen navigation tree `190797fdc87bfd868516ccb9e145a7afd4e5e47f` and isolated GC 03/04 commit `1ed7e29`, both based on `f1388e8`.
- Unified branch: `codex/core184-unified`; preserve GC 01/02 while merging the approved generic homepage/dispute contracts, payment fixes, and bounded advertising/payment facades.
- Historical scope note: this integration was isolated at the time; publication and production actions require separate Owner authorization.
- The existing private signing-key boundary above remains in force. Reuse only existing valid public test authorization envelopes through the native verifier; missing signing tests stay NOT VERIFIED.
