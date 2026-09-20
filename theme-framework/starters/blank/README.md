# Blank Daiying Theme Starter

Copy this directory when creating a new Daiying CMS theme.

## Start Here

1. Rename the theme directory.
2. Edit `theme.json`.
3. Replace generic copy and assets.
4. Keep `templates/home.php`, `templates/list.php`, `templates/content.php`, and `templates/error.php`.
5. Package as `content/themes/{theme_id}/...` with `market-package.json`.

## Stable API Usage

- Use `$context->get()` for ViewModel data.
- Use `$context->asset()` for files under `assets/`.
- Use one content URL helper everywhere a content card links to an article or page.
- Do not access database repositories from templates.
