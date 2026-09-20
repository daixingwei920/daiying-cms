# Daiying Theme Framework V1 Extraction Report

Date: 2026-09-20

## 1. Framework Path

`/Users/xingweidai/Documents/Codex/Daiying-Theme-Framework`

## 2. Frozen Reference

Current reference after the Daojia permalink fix:

`/Users/xingweidai/Documents/Codex/Daiying-Theme-Framework/examples/daojia-1.7.11`

Current package:

`/Users/xingweidai/Documents/Codex/Daiying-Theme-Framework/examples/daojia-1.7.11/official.theme.daojia-1.7.11.zip`

SHA-256:

`5ac7eafdafa1fe62576ead8f6b3ae8ef04f976d706b7dfa77d165c05f5e2c621`

Daojia 1.7.11 replaces 1.7.10 as the golden reference because it fixes article list card permalinks for Daiying CMS 1.2.65 ViewModel items with nested `content.slug`.

Historical reference:

Path:

`/Users/xingweidai/Documents/Codex/Daiying-Theme-Framework/examples/daojia-1.7.10`

Source package:

`/Users/xingweidai/Desktop/博客程序插件/official.theme.daojia-1.7.10.zip`

Copied package:

`/Users/xingweidai/Documents/Codex/Daiying-Theme-Framework/examples/daojia-1.7.10/official.theme.daojia-1.7.10.zip`

SHA-256:

`7e880697523b591697e2520a0e6f279371702994aee2e661f03a623935934cc0`

Frozen note:

`/Users/xingweidai/Documents/Codex/Daiying-Theme-Framework/examples/daojia-1.7.10/FROZEN_REFERENCE.md`

The original Daojia 1.7.10 package was not modified. The example folder is a read-only historical reference and must not be used as a new theme starting point.

## 3. Blank Starter

Path:

`/Users/xingweidai/Documents/Codex/Daiying-Theme-Framework/starters/blank`

Includes:

- `theme.json`
- `_theme.php`
- `templates/home.php`
- `templates/list.php`
- `templates/content.php`
- `templates/error.php`
- `css/theme.css`
- `js/theme.js`
- `config/theme.json`
- `assets/.gitkeep`

The blank starter contains no Daojia-specific copy, prop names, images, or cultural symbols.

## 4. Generic Components Extracted

Extracted as reusable guidance and starter/framework code:

- Responsive hero stage structure using `svh`/`dvh` and safe-area compatible layout.
- Generic layer roles: background, environment, figures, foreground, props, effects, text/signage, interaction.
- Generic transition presets: `scroll-open`, `light-reveal`, `split-open`, `none`.
- Interactive prop schema: id, image, desktop/mobile position, animation, click action, hover action, sound, z-index.
- Optional figure layer model that supports none, one, or multiple figures.
- Config-first scene data in `framework/immersive-culture-v1/config/theme.json`.
- Stable `TemplateContext` usage.
- Content permalink helper, including nested list ViewModel support via `item.content.slug`.
- Logo resolver rule: Theme Logo override, Site Global Logo, Site Name text fallback.
- Pagination helper usage based on actual Daiying CMS Theme API.

## 5. Daojia Code Not Extracted

Not extracted into framework/starter:

- Completed Daojia scene composition.
- Daojia-specific imagery.
- Daojia-specific prop names.
- Daojia-specific copywriting.
- Daojia-specific CSS class namespace as a required framework contract.

Reason:

The framework must support future culture themes without becoming a Daojia clone or requiring Core changes.

## 6. Core Modified?

No.

Core clean worktree inspected:

`/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/work/daiying-cms-core-clean-1.2.63`

Observed:

- HEAD: `357be8b chore: release Daiying CMS 1.2.66`
- Tag at HEAD: `v1.2.66`
- Current version files show `1.2.66`
- Existing untracked `outputs/` directory was already present and was not modified by this task.

No CMS Core file was changed by the framework extraction.

## 7. Purity Checks

Blank starter scan:

- Forbidden theme-specific terms: none found.

Framework module scan:

- Forbidden theme-specific terms: none found.

Terms checked:

- `三清`
- `太极`
- `香炉`
- `符箓`
- `三清铃`
- `法剑`
- `八卦镜`
- `道可道`
- `道法自然`
- `Daojia`
- `daojia`

## 8. Framework Independence

The reusable framework does not depend on Daojia assets, Daojia copy, Daojia-specific object names, or Daojia visual composition.

The framework can be reused by changing:

- `config/theme.json`
- theme assets
- CSS color/typography layer
- copy text
- optional transition preset
- optional prop and figure definitions

## 9. Future Theme Creation

Recommended path for the next theme:

1. Copy `starters/blank/`.
2. Choose a visual direction and asset set.
3. Optionally copy selected structure from `framework/immersive-culture-v1/`.
4. Fill `config/theme.json`.
5. Replace placeholder assets.
6. Run `docs/RELEASE_CHECKLIST.md`.
7. Package as `content/themes/{theme_id}/...` with `market-package.json`.

Do not start a new theme by editing `examples/daojia-1.7.10` or any frozen reference. Start from `starters/blank/`; use `examples/daojia-1.7.11` only to inspect verified behavior.

## 10. Validation Results

PHP syntax:

- `framework/immersive-culture-v1/**/*.php`: PASS
- `starters/blank/**/*.php`: PASS

Daojia Freeze Test:

- Current 1.7.11 source package copied and SHA-256 verified.
- Original package not modified.
- Extracted example kept under `examples/`.

Blank Purity Test:

- PASS.

Framework Independence Test:

- PASS.

CMS Core Test:

- PASS. No Core files changed.

Future Theme Test:

- PASS at design/framework level. The starter plus config-driven layer model is sufficient to begin a new cultural theme without modifying Core.

## 11. Git Status

This framework directory is outside the Daiying CMS Core Git worktree.

Core worktree status remains explainable:

- Existing untracked `outputs/`
- No framework files added to Core.

## 12. Dirty / Untracked

Created outside Core:

- `/Users/xingweidai/Documents/Codex/Daiying-Theme-Framework/`

No production site, official update server, Core release package, or theme market package was changed.

## 13. Can Demo Site Start?

Yes, from the theme-framework perspective.

The framework provides enough structure to begin a Demo Site foundation using existing Daiying CMS Theme API and theme packages. Demo Site work should remain Core-external unless a future audit finds a genuinely generic Theme API gap.

## 14. Next Recommended Action

Start the next theme from:

`/Users/xingweidai/Documents/Codex/Daiying-Theme-Framework/starters/blank`

Use:

`/Users/xingweidai/Documents/Codex/Daiying-Theme-Framework/docs/CREATE_NEW_THEME.md`

as the authoring guide.
