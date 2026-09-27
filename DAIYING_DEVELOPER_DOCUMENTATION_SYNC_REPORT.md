# Daiying Developer Documentation Sync Report

Date: 2026-09-27

Scope: website/developer documentation sync only.

Non-goals: no Core runtime changes, no version bump, no 1.2.71 release, no official.wechat changes.

## Source Of Truth

The source of truth is the GitHub Core repository documentation:

- Plugin SDK V1: `docs/plugin-sdk/README.md` plus the five-piece SDK documentation set.
- Theme API: `THEME_API_V1.md` and `docs/themes.md`.
- Theme Development: `DAIYING_THEME_DEVELOPMENT_SPEC_V1_PROPOSAL.md` until reviewed and approved.

`www.daiyingcms.com` and `daiying-cms-developer-docs` are publication/display layers. They should link to or mirror from the Core repository, not maintain a separate hand-edited normative copy.

## Website Old Entrypoints

Production `www.daiyingcms.com` was rendering developer links from the active website theme:

- `https://github.com/daixingwei920/daiying-cms-developer-docs/blob/main/docs/theme-development-spec.md`
- `https://github.com/daixingwei920/daiying-cms-developer-docs/blob/main/docs/plugin-development-spec.md`

The linked documents were dated 2026-08-31 and described Daiying CMS V1.2 broadly. They did not represent the current Plugin SDK V1 / Core 1.2.70 contract.

## New Entrypoints

Intended public developer documentation entry:

- Developer Documentation: `https://github.com/daixingwei920/daiying-cms-developer-docs`
- Plugin SDK V1 source: `https://github.com/daixingwei920/daiying-cms/blob/main/docs/plugin-sdk/README.md`
- Theme Development Proposal source: `https://github.com/daixingwei920/daiying-cms/blob/main/DAIYING_THEME_DEVELOPMENT_SPEC_V1_PROPOSAL.md`

The website should label Plugin SDK as:

```text
Daiying Plugin SDK V1 — Core 1.2.70+
```

Theme Development should be labeled as proposal/review state until the final V1 specification is approved.

## Plugin Docs Current/New Version

Current formal Plugin documentation:

- Development Specification: `DAIYING_PLUGIN_DEVELOPMENT_SPEC_V1.md`
- API Reference: `DAIYING_PLUGIN_API_REFERENCE_V1.md`
- Event Registry: `DAIYING_EVENT_REGISTRY_V1.md`
- Capability Registry: `DAIYING_CAPABILITY_REGISTRY_V1.md`
- Official Plugin Skeleton: `DAIYING_OFFICIAL_PLUGIN_SKELETON_V1/`

The Plugin docs match Core 1.2.70 public SDK concepts:

- `rawBody()`
- `content()`
- `frontUsers()`
- `license()`
- `registerBlockRenderer()`
- `registerScheduledTask()`
- class-name event registry

The old website Plugin document must remain Legacy / Superseded and must not be the current development guide.

## Theme Docs Current/New Version

Current formal Theme API documentation:

- `THEME_API_V1.md`
- `docs/themes.md`

Theme Development Specification status:

- New proposal: `DAIYING_THEME_DEVELOPMENT_SPEC_V1_PROPOSAL.md`
- Not final until reviewed.

The proposal is based on Core 1.2.70 implementation, bundled themes, Theme Framework 1.0.1, and existing Theme API docs.

## Theme Spec Differences Found

Compared with the older 2026-08-31 website theme spec:

- `theme_id` validation now allows hyphen separators; old spec said only lowercase letters, digits, and underscores.
- Core 1.2.70 exposes more `TemplateContext` helpers than the old spec listed.
- Core 1.2.70 has a stable theme asset serving contract for CSS, JS, images, fonts, and audio.
- Content templates may receive comments and adjacent article ViewModels.
- Site logo/favicons are passed as `site_logo_url` and `site_favicon_url`.
- Themes should render Core-provided `rendered_blocks`; plugin block rendering is handled by Core and Plugin SDK block renderer registry.
- Official Theme Framework 1.0.1 is now a practical starting point, but its blank starter manifest shape needs review before a final Theme Development Spec can treat it as normative.

## Theme Framework Status

Theme Framework 1.0.1 is useful as a recommended starting point:

- `theme-framework/starters/blank/`
- `theme-framework/framework/immersive-culture-v1/`
- `theme-framework/examples/daojia-1.7.11/`

It is not Core. It should remain a framework/reference layer unless and until its contracts are promoted into reviewed Core Theme Development documentation.

## Duplicate Maintenance Risk

Risk exists if:

- Core repo docs are updated but `daiying-cms-developer-docs` keeps full hand-copied specs.
- Production theme footer/homepage links point to stale standalone docs.
- CMS article pages manually embed normative technical specs.

Minimum prevention:

1. Core repository remains source of truth.
2. `daiying-cms-developer-docs` keeps only publication/index pages and legacy notices, linking back to Core docs.
3. Website theme links point to `daiying-cms-developer-docs` and Core docs, not to copied 2026-08-31 specs as current.
4. Any future website article should link to Core docs rather than paste the whole specification.

## Actual Publication Changes

Core repository:

- PR `#6`: `https://github.com/daixingwei920/daiying-cms/pull/6`
- Merge commit: `b0144ae2686b45a6e8e04a0cf8afb8292e0a7b3c`
- Added `DAIYING_THEME_DEVELOPMENT_SPEC_V1_PROPOSAL.md`
- Added this sync report

Developer documentation display repository:

- Repository: `https://github.com/daixingwei920/daiying-cms-developer-docs`
- Commit: `e436d85`
- `README.md` now identifies the Core repository as source of truth.
- `docs/plugin-development-spec.md` is now a Legacy / Superseded page that links to Plugin SDK V1.
- `docs/theme-development-spec.md` is now a Legacy / Superseded page that links to the Theme Development Specification V1 Proposal.
- `docs/theme-plugin-development-spec.md` is marked Legacy / Superseded before preserving historical 2026-08-31 content.

Production website display layer:

- Updated active website theme template: `/www/wwwroot/saas.daiyinggame.com/content/themes/daiying_official_clean/templates/_theme.php`
- Backup created: `/www/wwwroot/saas.daiyinggame.com/storage/backups/_theme-developer-docs-sync-20260927T043008Z.php`
- `DYO_DOCS_UPDATED` now shows `2026-09-27`.
- Footer and homepage developer cards now link to:
  - `https://github.com/daixingwei920/daiying-cms-developer-docs`
  - `https://github.com/daixingwei920/daiying-cms/blob/main/docs/plugin-sdk/README.md`
  - `https://github.com/daixingwei920/daiying-cms/blob/main/DAIYING_THEME_DEVELOPMENT_SPEC_V1_PROPOSAL.md`

## Actual Online Verification

Verified URLs:

- `https://www.daiyingcms.com/`
- `https://www.daiyingcms.com/docs`
- `https://www.daiyingcms.com/articles/develop-first-daiying-cms-plugin`
- `https://www.daiyingcms.com/articles/develop-daiying-cms-theme`

Results:

- `Daiying Developer Documentation` is visible.
- `Plugin SDK V1` is visible.
- `Theme Spec Proposal` is visible.
- Old default links to `daiying-cms-developer-docs/blob/main/docs/plugin-development-spec.md` and `daiying-cms-developer-docs/blob/main/docs/theme-development-spec.md` are not present on the verified pages.
- The verified pages do not contain `registerCron()`, `comment.created`, `commerce.order.paid`, or `ContentApi`.
- `https://raw.githubusercontent.com/daixingwei920/daiying-cms-developer-docs/main/README.md` shows Core repository as source of truth.
- `https://raw.githubusercontent.com/daixingwei920/daiying-cms-developer-docs/main/docs/plugin-development-spec.md` is marked Legacy / Superseded and points to Plugin SDK V1.
- `https://raw.githubusercontent.com/daixingwei920/daiying-cms-developer-docs/main/docs/theme-development-spec.md` is marked Legacy / Superseded and points to the Theme Development Proposal.
- `https://raw.githubusercontent.com/daixingwei920/daiying-cms/main/DAIYING_THEME_DEVELOPMENT_SPEC_V1_PROPOSAL.md` is reachable.
- Production `/health` remained `ok`, `NORMAL`, version `1.2.70`, release id `daiying-cms-core-update-1.2.70`.

GitHub and website are now aligned on the current Plugin SDK V1 entrypoint. Theme Development is intentionally aligned to a proposal/review document, not a final V1 spec.
