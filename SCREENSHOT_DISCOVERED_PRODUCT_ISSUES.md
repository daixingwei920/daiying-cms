# Screenshot Discovered Product Issues

Date: 2026-09-06

No blocking product bug was confirmed during the screenshot pass.

## Observations

| Area | Observation | Severity | Suggested Follow-Up |
| --- | --- | --- | --- |
| Theme manager | Some installed themes display dependency-missing notices. This may be correct if their required plugins are not installed or authorized, but it is not ideal for public marketing screenshots. | Low | Use theme marketplace for README until the demo site has matching theme/plugin dependencies installed. |
| Dashboard | The admin dashboard shows the current login name. | Low | Consider a built-in demo/privacy mode for future public screenshots. |
| Baidu media | The Baidu media root page can expose personal directory names. | Medium | For public demos, use a dedicated demo Baidu account or a safe demo folder. |
| Commerce alpha routes | `/admin/commerce` and `/admin/commerce/distribution` were not available as verified screenshot targets in this pass. | Informational | Capture Distribution only after the current implementation is deployed and visible in the live backend. |

## Non-Issues

- The plugin marketplace shows empty authorization-code inputs. No actual authorization-code value was visible.
- Payment provider pages show setup labels and public callback concepts, but no payment keys were visible.
- The online update page shows the public update server URL `https://updates.daiyingcms.com`, which is expected.
