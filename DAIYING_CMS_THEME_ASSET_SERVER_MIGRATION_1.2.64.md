# Daiying CMS Theme Asset Server Migration 1.2.64

This note is for existing production sites that previously allowed theme assets with per-theme web server aliases, such as a hard-coded `daiying_novel` Apache `Alias`.

Core 1.2.64 adds a generic theme asset serving contract:

```text
/content/themes/{theme_id}/assets/{asset_path}
```

The web server should forward only matching theme asset requests to the Daiying CMS front controller. Core then validates the theme id, the asset path, file extension, and filesystem boundary before serving the file.

Do not add one Alias per theme. Do not grant public access to the whole `content/themes` directory.

## Apache

For project-root document roots, place this rule before the broader `content/themes` deny rule:

```apache
RewriteRule ^content/themes/[A-Za-z0-9._-]+/assets/.+ public/index.php [L,QSA]
RewriteRule ^(config|storage|system|tests|scripts|content/plugins|content/themes)(/|$) - [F,L]
```

For virtual hosts that already use a custom `<Directory>` or `Alias` block, remove per-theme asset aliases after confirming the generic rule is active.

The generic rule must not grant direct directory access. It only routes matching requests into Core, where static asset rules are enforced.

## Nginx

For project-root document roots, place this location before the broader private-directory deny rule:

```nginx
location ~ ^/content/themes/[A-Za-z0-9._-]+/assets/.+ {
    rewrite ^ /public/index.php last;
}

location ~ ^/(config|storage|system|tests|scripts|content/plugins|content/themes)(/|$) {
    deny all;
}
```

If the server root already points at `public`, use the equivalent front-controller path used by that site. The important rule is that only `/content/themes/{theme_id}/assets/**` reaches Core, while the rest of `content/themes` remains private.

## Security Boundary

Core 1.2.64 only serves public theme asset files below `assets/` with approved static extensions:

```text
css, js, mjs, png, jpg, jpeg, gif, webp, svg, ico, woff, woff2, ttf
```

The following must remain blocked:

- `templates/*.php`
- `_theme.php`
- any other PHP file
- `theme.json`
- hidden files
- `.env`
- `.git`
- source/config files

## Verification

After installing the 1.2.64 Core update and applying the server rule, verify:

```text
GET /content/themes/daiying_novel/assets/style.css
GET /content/themes/guofeng_zhuhong/assets/css/base.css
GET /content/themes/test_theme/assets/style.css
```

All valid asset requests should return `200` with the correct static content type.

Also verify:

```text
GET /content/themes/test_theme/templates/home.php
GET /content/themes/test_theme/theme.json
GET /content/themes/test_theme/assets/secret.php
GET /content/themes/test_theme/assets/.hidden.css
```

These must return `403` or `404`.

