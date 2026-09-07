# Old Domain Audit

Current official website:

```text
https://www.daiyingcms.com
```

Current official update server:

```text
https://updates.daiyingcms.com
```

This audit records domain references found during the GitHub promotion pass. No global replacement was performed.

## Findings

| File | Reference | Classification | Recommendation |
| --- | --- | --- | --- |
| `scripts/build_full_install_package.php` | `updates.daiyinggame.com/` | Code configuration / package exclusion guard | Keep or review manually. This is an exclusion rule that prevents private update-server files from entering public ZIPs. Do not remove without replacing it with an equivalent guard. |
| `content/plugins/official.novel-collector/plugin.php` | `book.daixingwei.cn` | Code fallback / private domain | Must review before public release. It appears to be a fallback host string in a bundled plugin and may expose a private-site assumption. |
| `DAIYING_THEME_PRODUCTIZATION_REPORT.md` | `www.daxingwei.cn`, `www.daixingwei.cn` | Historical report | Can remain as historical audit context. |
| `README.md` | `https://updates.daiyingcms.com` | Current official domain | Keep. |
| `config/app.php` | `https://updates.daiyingcms.com` | Current official config | Keep. |
| `config/app.example.php` | `https://updates.daiyingcms.com` | Current official config | Keep. |
| `system/core/Install/InstallController.php` | `https://updates.daiyingcms.com` | Current official config | Keep. |
| `system/core/Admin/AdminController.php` | `https://updates.daiyingcms.com` | Current official UI/config | Keep. |

## Must Modify

- `content/plugins/official.novel-collector/plugin.php`: review whether `book.daixingwei.cn` should be replaced with a neutral local fallback or removed from public package behavior.

## Historical Material Can Remain

- `DAIYING_THEME_PRODUCTIZATION_REPORT.md`: historical domain references are audit text.

## Test Data

- No test fixture domain requiring immediate change was found in this scan.

## Code Configuration

- `updates.daiyingcms.com` is the current official update server and should remain.
- `updates.daiyinggame.com` appears only as a forbidden package-entry guard and should not be removed casually.

## Unable To Judge

- None beyond the bundled novel plugin fallback noted above.
