# Daiying Theme Framework

Reusable theme foundation for Daiying CMS official themes.

This repository is not Daiying CMS Core. It contains theme design rules, reusable theme-layer conventions, a blank starter, and frozen reference examples.

## Current Baseline

- Daiying CMS Theme API: v1
- Framework version: 1.0.1
- Golden reference: `examples/daojia-1.7.11/`
- Starter: `starters/blank/`
- Framework module: `framework/immersive-culture-v1/`

## Goals

- Build new official themes without changing CMS Core.
- Keep theme assets, templates, responsive hero scenes, and interactions reusable.
- Preserve the current Daiying CMS Theme API contracts.
- Avoid copying a completed theme directly into a new cultural theme.

## Non-Goals

- This framework does not publish or modify any theme package by itself.
- This framework does not add CMS Core APIs.
- This framework does not include production secrets, market signing keys, or update-server code.

## Key Rules

- Themes read data through `Cms\Core\Theme\TemplateContext`.
- `TemplateContext::asset('css/theme.css')` is relative to the theme `assets/` directory.
- Standard templates are `templates/home.php`, `templates/list.php`, `templates/content.php`, and `templates/error.php`.
- Content cards must generate real article/page permalinks; helpers must support both top-level `slug/url` and nested `content.slug/content.url` ViewModel shapes.
- Logo resolution should follow: theme override, then site global logo, then site name text.

## Directory Guide

- `framework/immersive-culture-v1/`: reusable hero scene, layer, prop, and template conventions.
- `starters/blank/`: clean starting theme with no theme-specific cultural copy or imagery.
- `examples/daojia-1.7.11/`: current frozen reference, not a development base.
- `examples/daojia-1.7.10/`: historical reference only; do not use as a new theme starting point.
- `assets-guide/`: asset preparation, naming, responsive, animation, and layer rules.
- `docs/`: how to create, port, and release themes.
