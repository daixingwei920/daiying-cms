# Theme Release Checklist

## API

- [ ] Uses `TemplateContext` only.
- [ ] No database/repository access from templates.
- [ ] `templates/home.php` exists.
- [ ] `templates/list.php` exists.
- [ ] `templates/content.php` exists.
- [ ] `templates/error.php` exists.

## Content

- [ ] Home article links work.
- [ ] Grid article links work.
- [ ] Banner article links work if present.
- [ ] List article links work.
- [ ] Search result article links work if present.
- [ ] Page links work.
- [ ] Chinese and special-character slugs are encoded.
- [ ] Missing `url` fields still produce correct permalinks.
- [ ] `/articles` list items with nested `content.slug` produce clickable article links.
- [ ] No content card uses `#` as a real content link.

## Logo

- [ ] Site global logo displays when configured.
- [ ] Theme logo override works when configured.
- [ ] Text fallback works when no logo exists.
- [ ] Mobile logo uses same resolver.

## Assets

- [ ] Uses `$context->asset('css/...')`, not `assets/css/...`.
- [ ] CSS and JS load from `/content/themes/{theme_id}/assets/...`.
- [ ] No PHP, config, hidden files, or secrets are public assets.

## Responsive

- [ ] Desktop home.
- [ ] Desktop list.
- [ ] Desktop content.
- [ ] Mobile home.
- [ ] Mobile list.
- [ ] Mobile content.
- [ ] iPhone Safari safe-area behavior.

## Package

- [ ] `market-package.json` exists.
- [ ] Package path is `content/themes/{theme_id}/...`.
- [ ] Each market file declares SHA-256.
- [ ] Core constraint uses theme compatibility semantics, not update-server minimum-upgrade semantics.
- [ ] Theme does not require manual web server changes.
