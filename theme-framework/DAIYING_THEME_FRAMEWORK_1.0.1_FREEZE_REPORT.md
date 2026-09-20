# Daiying Theme Framework 1.0.1 Freeze Report

Date: 2026-09-20

## Result

Theme Framework baseline updated from Daojia 1.7.10 to Daojia 1.7.11.

Daojia 1.7.11 is now the current frozen golden reference because it fixes the real Daiying CMS 1.2.65 `/articles` list ViewModel permalink shape.

## New Golden Reference

- Path: `examples/daojia-1.7.11/`
- Package: `examples/daojia-1.7.11/official.theme.daojia-1.7.11.zip`
- SHA-256: `5ac7eafdafa1fe62576ead8f6b3ae8ef04f976d706b7dfa77d165c05f5e2c621`
- Market package id: `daojia:1.7.11:stable`

## Why 1.7.10 Was Superseded

Daojia 1.7.10 generated empty article card links on `/articles` because its helper only read top-level:

- `url`
- `slug`
- `content_type`

Daiying CMS 1.2.65 list routes can pass each article as a content ViewModel where the raw content record is nested under:

- `content.url`
- `content.slug`
- `content.content_type`

The Theme Framework helper now supports both shapes.

## Updated Helper Contract

Theme content permalink helpers must check, in order:

1. `$item['url']`
2. `$item['content']['url']`
3. `$item['slug']`
4. `$item['content']['slug']`
5. `$item['content_type']`
6. `$item['content']['content_type']`

Article links use:

```text
/articles/{rawurlencode(slug)}
```

Page links use:

```text
/{rawurlencode(slug)}
```

Helpers must not use `#`, empty `href`, or `javascript:void(0)` as content permalink fallbacks.

## Files Updated

- `README.md`
- `THEME_DESIGN_SPEC.md`
- `docs/CREATE_NEW_THEME.md`
- `docs/PORT_FROM_DAOJIA.md`
- `docs/RELEASE_CHECKLIST.md`
- `DAIYING_THEME_FRAMEWORK_V1_EXTRACTION_REPORT.md`
- `starters/blank/_theme.php`
- `framework/immersive-culture-v1/components/theme_helpers.php`
- `examples/daojia-1.7.11/FROZEN_REFERENCE.md`
- `examples/daojia-1.7.11/daojia/**`
- `examples/daojia-1.7.11/official.theme.daojia-1.7.11.zip`

## Preserved

- `examples/daojia-1.7.10/` remains unchanged as a historical reference only.
- Daiying CMS Core was not modified.
- Existing production themes were not modified.

## Validation

- `php -l starters/blank/_theme.php`: PASS
- `php -l framework/immersive-culture-v1/components/theme_helpers.php`: PASS
- `php -l examples/daojia-1.7.11/daojia/_theme.php`: PASS
- Blank starter nested article permalink test: PASS
- Blank starter nested page permalink test: PASS
- Immersive framework nested article permalink test: PASS
- Immersive framework nested page permalink test: PASS
- Daojia 1.7.11 package SHA-256 verified: PASS

## Next Theme Rule

All new themes should start from `starters/blank/` after this update. Use `examples/daojia-1.7.11/` only as a read-only behavior reference.
