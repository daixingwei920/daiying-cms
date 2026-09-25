# Theme Audio Assets Contract Implementation Report

## ROOT_CAUSE

`TemplateContext::asset('audio/fate-question.mp3')` correctly generates the stable theme asset URL:

```text
/content/themes/{theme_id}/assets/audio/fate-question.mp3
```

The request is routed through `Application::maybeServeThemeAsset()` to `ExtensionAssetController::showThemeContentAsset()`.

The failure was not caused by the MP3 file, CSP, `/media/{id}`, or the theme asset URL contract. The root cause was the Core theme asset allowlist: `ExtensionAssetController` allowed CSS, JavaScript, image, JSON and font extensions, but did not allow audio extensions. Therefore `.mp3` returned `404 Asset not found`.

The existing asset controller also did not define a formal byte-range response contract for large/binary media assets.

## OLD_BEHAVIOR

- `css`, `js`, image and font theme assets were served from `content/themes/{theme_id}/assets/**`.
- Theme PHP templates, `theme.json`, hidden files, config/source files and unsafe paths were rejected.
- Audio extensions such as `mp3`, `ogg`, `wav`, `m4a` and `aac` were not public theme assets.
- Full asset responses returned `200` but did not advertise `Accept-Ranges: bytes`.
- `Range: bytes=...` was not handled for theme assets.

## NEW_CONTRACT

Theme Audio Assets v1:

```text
content/themes/{theme_id}/assets/audio/
```

Supported extensions:

- `.mp3` -> `audio/mpeg`
- `.ogg` / `.oga` -> `audio/ogg`
- `.wav` -> `audio/wav`
- `.m4a` -> `audio/mp4`
- `.aac` -> `audio/aac`

Theme code continues to use the existing Theme API:

```php
$context->asset('audio/fate-question.mp3');
```

Themes must not hard-code `/media/{id}` for packaged audio. `/media/{id}` is site data, while packaged theme audio belongs in the theme ZIP and must work immediately after installation.

## SECURITY_BOUNDARY

The existing theme asset security boundary remains fail-closed:

- Only installed theme files under `content/themes/{theme_id}/assets/**` are public.
- `templates/*.php`, `_theme.php`, `theme.json`, hidden files, `.env`, source/config files and executable extensions are not public.
- Path traversal, encoded traversal, absolute paths and symlink escape are rejected.
- Audio support is extension allowlist based; it does not open arbitrary files.
- PHP/PHAR/PHTML/shell files remain blocked.

## HTTP_RANGE_BEHAVIOR

Theme assets now include:

- `Content-Type`
- `Content-Length`
- `Cache-Control: public, max-age=31536000, immutable`
- `ETag`
- `X-Content-Type-Options: nosniff`
- `Accept-Ranges: bytes`

Single byte range requests are supported:

```text
Range: bytes=0-1023
```

Valid ranges return:

- `206 Partial Content`
- `Content-Range: bytes start-end/size`
- ranged `Content-Length`

Unsatisfiable ranges return:

- `416`
- `Content-Range: bytes */size`

This is sufficient for HTML5 `<audio>` download, buffering and seeking behavior at the HTTP contract level.

## FILES_CHANGED

Core clean worktree:

- `system/core/Extension/ExtensionAssetController.php`
- `system/core-manifest.json`
- `tests/theme_asset_serving_contract.php`
- `THEME_API_V1.md`
- `docs/themes.md`
- `theme-framework/docs/CREATE_NEW_THEME.md`
- `theme-framework/THEME_DESIGN_SPEC.md`

Daiying Theme Framework dev docs were also synchronized:

- `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/work/daiying-theme-framework-dev/THEME_API_V1.md`
- `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/work/daiying-theme-framework-dev/docs/themes.md`
- `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/work/daiying-theme-framework-dev/theme-framework/docs/CREATE_NEW_THEME.md`
- `/Users/xingweidai/Documents/Codex/2026-09-03/daiying-cms-core-theme/work/daiying-theme-framework-dev/theme-framework/THEME_DESIGN_SPEC.md`

No `xifang_ersheng` theme files were modified.

## TEST_RESULTS

Passed:

```text
php -l system/core/Extension/ExtensionAssetController.php
php -l tests/theme_asset_serving_contract.php
php tests/theme_asset_serving_contract.php
php tests/theme_api_v1.php
php tests/base_path_deployment.php
core manifest integrity check
git diff --check
```

Covered by `tests/theme_asset_serving_contract.php`:

- existing CSS asset 200
- market-installed theme CSS asset 200 without theme ID hard-coding
- arbitrary third theme CSS asset 200
- MP3 asset 200
- MP3 MIME `audio/mpeg`
- `Accept-Ranges: bytes`
- full `Content-Length`
- `Range: bytes=0-3` -> 206
- suffix range -> 206
- invalid range -> 416
- OGG MIME
- WAV MIME
- M4A MIME
- missing audio -> 404
- templates blocked
- `theme.json` blocked
- PHP blocked
- PHAR blocked
- hidden files blocked
- `.env` blocked
- traversal blocked
- encoded traversal blocked
- symlink escape blocked
- non-GET blocked

## REAL_BROWSER_E2E

Not executed in this task.

Reason: this task explicitly did not modify `xifang_ersheng`, did not create version `0.1.18`, and did not publish a theme package. The repository/workspace available to this task did not contain the real `xifang_ersheng` packaged audio file to mount in a browser without changing the theme.

The HTTP-level contract required by browsers was verified with automated GET and Range tests. Final real-browser playback for:

```php
$context->asset('audio/fate-question.mp3')
```

should be performed when `xifang_ersheng 0.1.18` packages the audio under `assets/audio/`.

## FRAMEWORK_DOCS_UPDATED

Updated Theme API and Theme Framework documentation now defines:

- recommended `assets/audio/` directory;
- supported formats;
- `asset('audio/...')` usage;
- prohibition on `/media/{id}` for packaged audio;
- browser autoplay limitations;
- user gesture fallback;
- loop/preload/volume guidance;
- mobile browser notes;
- unchanged security boundary.

## CORE_CHANGE_REQUIRED: YES

Yes. This is a generic Core Theme Runtime capability because all official and third-party themes need one stable, secure way to serve packaged audio assets after installation.

This was implemented in the existing generic `ExtensionAssetController`, not as an `xifang_ersheng` special case.

## READY_FOR_XIFANG_ERSHENG_0.1.18: YES

Yes, the Core/Theme Framework contract is ready for `xifang_ersheng 0.1.18` to package voiceover, ambience and effects under `assets/audio/`.

Before market release, `xifang_ersheng 0.1.18` should still run a real browser playback test and remove any temporary audio diagnostics that are not needed for users.
