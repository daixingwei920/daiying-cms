# Daiying CMS GitHub Promotion Report

Date: 2026-09-06

## Distribution Definition Correction

The initial GitHub promotion pass incorrectly described Distribution as commercial extension packaging, license-based delivery, update workflows, deployable site capabilities, and related marketplace/update foundations.

That was wrong. Daiying CMS Distribution is not the plugin marketplace, commercial plugin licensing, authorization-code delivery, the CMS update system, Capability Pack, Site Vault, Shadow Upgrade, or a mechanism for packaging and deploying plugins to a site.

The corrected definition is:

```text
Distribution is the Daiying CMS layer being developed for sending prepared content and commerce data to supported external channels.
```

The intended product flow is:

```text
Content / product creation
-> media resources
-> Distribution
-> external channel selection or matching
-> AI / rule adaptation
-> Provider / Connector
-> external delivery
-> success, failure, channel error, or AI error status
```

Current public repository status:

- After rebasing onto the current remote `main`, alpha Distribution code is present under `content/plugins/official.commerce`.
- No dedicated stable `system/core/Distribution` directory was found.
- No final standalone Distribution Provider/Connector specification was found.
- No stable channel list or final Distribution user workflow should be advertised as production-ready.
- Historical `distribution-server` directories were found outside this repository under older Codex output paths, but they are not current Daiying CMS Core source in this public Git repository and were not used as README truth.

Confirmed current capabilities in this public branch:

- Content and media foundations exist in Core.
- Commerce foundations exist for payment providers, paid access, card-code delivery, commercial license storage, product/order flows, AI modules, and alpha Distribution channel management.
- Marketplace, update, Capability Pack, Site Vault, and Shadow Upgrade foundations exist as separate architecture areas.
- Distribution-specific implementation currently lives in `content/plugins/official.commerce` and remains In Development.

Capabilities still owned by the other active Distribution development thread:

- Final supported external channel list.
- Final Provider/Connector contract.
- Final AI/rule adaptation behavior.
- Final channel error and AI error workflow.
- Stable admin UI screenshots.
- Final stable documentation.

README now uses the conservative wording:

```text
A distribution layer being developed for sending Daiying CMS content and commerce data to supported external channels.
```

Files corrected in this pass:

- `README.md`
- `README.zh-CN.md`
- `docs/distribution.md`
- `docs/README.md`
- `docs/developers.md`
- `examples/README.md`
- `GITHUB_REPOSITORY_SETTINGS.md`
- `DAIYING_CMS_GITHUB_PROMOTION_REPORT.md`

## 1. Initial Repository State

- Worktree was clean before changes.
- Latest local commit: `02eb142 release: bump Daiying CMS to 1.2.24`.
- Current app version in `config/app.php` and `config/app.example.php`: `1.2.24`.
- Existing GitHub-facing file before this pass: `README.md`.
- Missing before this pass: `LICENSE`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`, `.github/ISSUE_TEMPLATE/`, `.github/PULL_REQUEST_TEMPLATE.md`, `docs/`, `examples/`, screenshot assets.
- License was not created because the project license is not explicit in the repository.

## 2. README Structure After Update

`README.md` is now English-first and presents Daiying CMS as a public project homepage:

- Project tagline.
- Official Website, Documentation, Download, Releases, Plugins, Themes.
- Product model.
- Quick Start.
- Major capabilities.
- Distribution in-development section.
- Commerce section.
- Plugin and theme summaries.
- Documentation links.
- Release/update notes.
- Screenshot and license status.

`README.zh-CN.md` was added as the Chinese companion README.

## 3. Distribution Presentation

Distribution is shown as **In Development**, not Stable.

The README now defines Distribution as the in-development external-channel distribution layer for Daiying CMS content and commerce data. It explicitly separates Distribution from Marketplace, commercial plugin licensing, authorization-code delivery, Core updates, Capability Pack, Site Vault, and Shadow Upgrade.

After rebasing onto the current remote `main`, the repository contains alpha Commerce Distribution code under `content/plugins/official.commerce`. The README and docs now state that this is not a stable Core module and that the final Provider/Connector specification should continue to follow the real merged implementation as it matures.

## 4. Commerce Presentation

Commerce is shown as **Foundation / Beta**.

The docs mention only verified repository capabilities: payment provider settings, manual payment, hosted redirect provider foundation, payment attempts, paid content/download access, card-code delivery, and commercial product/license storage.

## 5. Plugin Presentation

`docs/plugins.md` lists only plugin manifests present in the repository:

- `faq_block`
- `local.storage.baidu`
- `official.commerce`
- `official.friend-links`
- `official.novel-collector`
- `official.video-collector`

No unverified official plugin was advertised.

## 6. Theme Presentation

`docs/themes.md` lists only theme manifests present in the repository:

- `default`
- `daiying_media`
- `daiying_novel`
- `daiying-video`
- `safe`

No fake screenshot or nonexistent theme preview was added.

## 7. Documentation Navigation

Added `docs/README.md` with grouped navigation:

- Getting Started
- User Guide
- Developer Guide
- Commerce
- Distribution
- Deployment
- Architecture Notes

An `examples/README.md` placeholder was added to document that no verified examples are currently tracked and to define safe future example expectations.

## 8. Quick Start

The README Quick Start now covers:

1. Environment requirements.
2. GitHub Releases download.
3. Server extraction.
4. `public` document root.
5. `/install`.
6. SQLite or MySQL/MariaDB database test.
7. Administrator creation.
8. `/admin/login`.

The steps are based on the current installer implementation.

## 9. New GitHub Base Files

Added:

- `CHANGELOG.md`
- `CONTRIBUTING.md`
- `SECURITY.md`
- `CODE_OF_CONDUCT.md`
- `.github/ISSUE_TEMPLATE/bug_report.md`
- `.github/ISSUE_TEMPLATE/feature_request.md`
- `.github/PULL_REQUEST_TEMPLATE.md`

Not added:

- `LICENSE`, because the repository does not currently declare a license.

## 10. Screenshots

Real product screenshots were captured from the current live Daiying CMS admin UI and added under `.github/assets/screenshots/`.

README files now use:

- Admin dashboard.
- Content editor.
- Media library.
- Plugin marketplace.
- Card-delivery Commerce.
- Theme marketplace.
- Online update.

Additional captured screenshots include plugin manager, theme manager, payment providers, safe-cropped Baidu media, and a GitHub Social Preview draft.

Distribution preview remains unavailable for README because the current live backend did not provide a verified Distribution preview page during this screenshot pass.

## 11. Release Findings

GitHub latest release check:

- Tag: `v1.2.24`.
- Name: `Daiying CMS 1.2.24`.
- Published: `2026-09-06T05:04:57Z`.
- Release URL: <https://github.com/daixingwei920/daiying-cms/releases/tag/v1.2.24>.
- ZIP asset: `daiying-cms-1.2.24-stable.zip`.
- ZIP SHA-256 asset digest: `689ecb05b0a461bc5cdc5393589baf9eac8fdf2bb3c1b75e7e4b159e609b79ee`.
- Manifest and `.sha256` assets are present.

No release was republished.

## 12. Old Domain Scan

Added `GITHUB_OLD_DOMAIN_AUDIT.md`.

Main findings:

- `updates.daiyingcms.com` is current and should remain.
- `scripts/build_full_install_package.php` contains an `updates.daiyinggame.com/` forbidden-entry guard; this should not be removed without an equivalent private-infrastructure exclusion.
- `content/plugins/official.novel-collector/plugin.php` contains `book.daixingwei.cn` as a fallback host; this should be reviewed before future public plugin release packaging.
- Historical report mentions of `www.daxingwei.cn` / `www.daixingwei.cn` can remain as historical audit text.

## 13. Repository Settings Suggestions

Added `GITHUB_REPOSITORY_SETTINGS.md` with:

- Repository description.
- Website URL.
- GitHub Topics.
- Social Preview guidance.
- README tagline.
- Chinese and English descriptions.
- Manual GitHub operations still needed.

## 14. Manual GitHub Operations Still Needed

- Add GitHub repository description.
- Set Website to `https://www.daiyingcms.com`.
- Add recommended topics.
- Upload a social preview image after a real branded asset exists.
- Review the draft `.github/assets/github-social-preview.png` and upload it in GitHub repository settings if approved.
- Confirm and add a `LICENSE` if public reuse is intended.

## 15. Modified Files

- `README.md`
- `README.zh-CN.md`
- `CHANGELOG.md`
- `CONTRIBUTING.md`
- `SECURITY.md`
- `CODE_OF_CONDUCT.md`
- `.github/ISSUE_TEMPLATE/bug_report.md`
- `.github/ISSUE_TEMPLATE/feature_request.md`
- `.github/PULL_REQUEST_TEMPLATE.md`
- `docs/README.md`
- `docs/installation.md`
- `docs/plugins.md`
- `docs/themes.md`
- `docs/commerce.md`
- `docs/distribution.md`
- `docs/developers.md`
- `docs/update-system.md`
- `examples/README.md`
- `GITHUB_SCREENSHOT_REQUIREMENTS.md`
- `GITHUB_REPOSITORY_SETTINGS.md`
- `GITHUB_OLD_DOMAIN_AUDIT.md`
- `DAIYING_CMS_GITHUB_PROMOTION_REPORT.md`
- `DAIYING_CMS_SCREENSHOT_REPORT.md`
- `SCREENSHOT_DISCOVERED_PRODUCT_ISSUES.md`
- `.github/assets/github-social-preview.png`
- `.github/assets/screenshots/*.png`

## 16. Git Diff Summary

Current work after screenshot capture adds real PNG assets, updates README screenshot sections, refreshes Distribution status after rebase, and records screenshot QA notes.

The promotion commit and Distribution correction commit were rebased onto the current remote `main`, so their local hashes changed. Screenshot assets should be committed separately as `docs: add real Daiying CMS product screenshots`.

## 17. Test Results

- `git diff --check`: rerun after screenshot capture before commit.
- GitHub release metadata checked with `gh release view`.
- No PHP business tests were run because this task modified documentation and GitHub metadata files only.
