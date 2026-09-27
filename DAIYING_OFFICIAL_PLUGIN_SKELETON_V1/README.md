# Official SDK Skeleton V1

This is the minimum official plugin skeleton for Daiying Plugin SDK Foundation V1.

It demonstrates:

- `plugin.php` callable entry.
- `plugin.json` manifest and capabilities.
- Admin route and front route.
- CSRF form.
- Secret Store.
- PluginDataStore.
- Scheduler.
- Event listener.
- Raw webhook body via `Request::rawBody()`.
- ContentService draft creation.
- Auth read example without programmatic login.
- Block registration and block renderer.
- A focused contract test.

It intentionally does not demonstrate payment providers, AI providers, queue workers, commercial licensing, or programmatic user login.

## Test

From the Core root:

```bash
php DAIYING_OFFICIAL_PLUGIN_SKELETON_V1/tests/skeleton_contract.php
```

