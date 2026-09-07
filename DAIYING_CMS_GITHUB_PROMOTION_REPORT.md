# Daiying CMS GitHub Promotion Report

Date: 2026-09-06

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
- Distribution preview section.
- Commerce section.
- Plugin and theme summaries.
- Documentation links.
- Release/update notes.
- Screenshot and license status.

`README.zh-CN.md` was added as the Chinese companion README.

## 3. Distribution Presentation

Distribution is shown as **Development / Preview**, not Stable.

The README explains Distribution as the planned layer for commercial extension packaging, license-based delivery, update workflows, and deployable site capabilities. It also records the current confirmed foundations in the public repository: Market infrastructure, commercial license schema, card delivery, payment provider foundation, signed updates, capability packs, site vault, and shadow upgrade schema.

## 4. Commerce Presentation

Commerce is shown as **Foundation / Beta**.

The docs mention only verified repository capabilities: payment provider settings, manual payment, hosted redirect provider foundation, payment attempts, paid content/download access, card-code delivery, and commercial product/license storage.

## 5. Plugin Presentation

`docs/plugins.md` lists only plugin manifests present in the repository:

- `faq_block`
- `local.storage.baidu`
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

## 10. Screenshot Gaps

No verified public screenshot assets were found.

Added `GITHUB_SCREENSHOT_REQUIREMENTS.md` with required future screenshots for:

- Admin dashboard.
- Content editor.
- Media library.
- Plugin manager.
- Theme manager.
- Commerce.
- Distribution.
- Online update.

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

## 16. Git Diff Summary

The tracked diff before adding new files showed:

```text
README.md | 279 ++++++++++++++++++++++++++++++--------------------------------
1 file changed, 136 insertions(+), 143 deletions(-)
```

New files are currently untracked until staged for commit.

## 17. Test Results

- `git diff --check`: PASS.
- GitHub release metadata checked with `gh release view`.
- No PHP business tests were run because this task modified documentation and GitHub metadata files only.
