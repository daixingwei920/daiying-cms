# Theme Development Spec V1 Finalization And Publication Report

Date: 2026-09-27

Scope: docs/framework-only publication.

Non-goals respected:

- No Core Runtime changes.
- No updater changes.
- No Core version bump.
- No official.wechat changes.
- No release publication.

## Source Of Truth

The Core repository is the source of truth:

```text
https://github.com/daixingwei920/daiying-cms
```

Current Theme Development source of truth:

```text
DAIYING_THEME_DEVELOPMENT_SPEC_V1.md
```

Target:

```text
Daiying CMS Core 1.2.70+
```

`www.daiyingcms.com` and `daiying-cms-developer-docs` are publication/display layers.

## GitHub Core Publication

Core PR:

```text
https://github.com/daixingwei920/daiying-cms/pull/9
```

Merged commit:

```text
5113715ec839e27b42e1bdb03ea41e306bd27f0a
```

Changes:

- Added `DAIYING_THEME_DEVELOPMENT_SPEC_V1.md`.
- Changed `DAIYING_THEME_DEVELOPMENT_SPEC_V1_PROPOSAL.md` to a Legacy / Superseded pointer.
- Updated `README.md`, `docs/README.md`, `docs/developers.md`, and `docs/themes.md` to make Theme Development Specification V1 and Daiying Theme Framework the current entrypoints.
- Marked old 2026-08-31 website Theme Development Specification content as Legacy / Superseded in docs wording.

Validation:

```text
php tests/theme_api_v1.php
php tests/theme_asset_serving_contract.php
```

Both passed.

## Developer Docs Publication Layer

Repository:

```text
https://github.com/daixingwei920/daiying-cms-developer-docs
```

Commit:

```text
6c7609323975014112ce76440be98add7c1dbe56
```

Changes:

- `README.md` now links Theme Development to `DAIYING_THEME_DEVELOPMENT_SPEC_V1.md` and the Daiying Theme Framework.
- `docs/theme-development-spec.md` remains Legacy / Superseded and points to Theme Spec V1.
- `docs/theme-plugin-development-spec.md` remains Legacy / Superseded and points to Theme Spec V1.

## Website Publication

Production website template changed:

```text
/www/wwwroot/saas.daiyinggame.com/content/themes/daiying_official_clean/templates/_theme.php
```

Backup:

```text
/www/wwwroot/saas.daiyinggame.com/storage/backups/_theme-theme-spec-v1-final-20260927T051035Z.php
```

Website footer/developer entry now links to:

```text
https://github.com/daixingwei920/daiying-cms/blob/main/DAIYING_THEME_DEVELOPMENT_SPEC_V1.md
```

Visible label:

```text
Theme Spec V1（Core 1.2.70+）
```

The website no longer uses `Theme Spec Proposal` as the theme development entry.

## Actual Online Verification

Verified GitHub raw URLs:

- `https://raw.githubusercontent.com/daixingwei920/daiying-cms/main/DAIYING_THEME_DEVELOPMENT_SPEC_V1.md`
- `https://raw.githubusercontent.com/daixingwei920/daiying-cms/main/DAIYING_THEME_DEVELOPMENT_SPEC_V1_PROPOSAL.md`
- `https://raw.githubusercontent.com/daixingwei920/daiying-cms-developer-docs/main/README.md`
- `https://raw.githubusercontent.com/daixingwei920/daiying-cms-developer-docs/main/docs/theme-development-spec.md`
- `https://raw.githubusercontent.com/daixingwei920/daiying-cms-developer-docs/main/docs/theme-plugin-development-spec.md`

Results:

- Theme Spec V1 is reachable and marked `Status: Final`.
- Theme Spec V1 target is `Daiying CMS Core 1.2.70+`.
- Proposal file is marked `Legacy / Superseded`.
- Developer docs README points to Theme Spec V1 and Theme Framework.
- Legacy theme docs point to Theme Spec V1.

Verified website URLs:

- `https://www.daiyingcms.com/`
- `https://www.daiyingcms.com/docs`
- `https://www.daiyingcms.com/articles/develop-daiying-cms-theme`
- `https://www.daiyingcms.com/articles/docs-theme-system`

Results:

- `Theme Spec V1` is visible.
- `Theme Spec Proposal` is not visible.
- `DAIYING_THEME_DEVELOPMENT_SPEC_V1_PROPOSAL` is not visible.
- Old `docs/theme-development-spec.md` is not used as the default website entry.

Production health:

```json
{"status":"ok","mode":"NORMAL","version":"1.2.70","release_id":"daiying-cms-core-update-1.2.70","maintenance":false,"installed":true}
```

## Final State

Theme Spec status:

```text
V1 FINAL / PUBLISHED
```

GitHub and website publication are aligned to the Core repository source of truth.

NEXT ACTION remains:

```text
Daiying CMS Core 1.2.71 Release Gate
```

