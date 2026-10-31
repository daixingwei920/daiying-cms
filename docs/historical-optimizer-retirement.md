# Historical SEO and content optimizer retirement

Owner decision: 2026-10-30. Unpublished candidate based on merged PR75,
`ee987eb6cb4c7800b6df8ed95ec551c1a3e19dbf`. No Core version change, merge,
production deployment or publication is authorized by this task.

The two tools “历史文章 SEO 补全” and “历史内容优化” are removed from the sidebar,
route registrations, controller actions and dedicated optimization service.
`/admin/seo/historical-backfill` and `/admin/seo/content-quality`, including all
descendants, return HTTP 410 with private/no-store for every method before
plugin/catch-all routing. Old action URLs cannot scan, generate or apply changes.
There is no independent worker or CLI entry for the retired service.

Normal article creation/editing and AI writing, manual SEO fields, keyword
management, crawler statistics, search-engine connections, shared AI services,
member identity and plugin/theme APIs remain in scope as regression controls.

The published historical migration is unchanged. Existing articles, URLs, SEO,
pending optimization suggestions, jobs, logs and migration records are retained;
there is no destructive migration or automatic application of old suggestions.
Fresh installations still create the historical tables for migration compatibility.
The internal service is removed from the Core manifest through ManifestBuilder;
all remaining Core file hashes must match. No integrity protection is disabled.

## Acceptance

The former `tests/historical_article_seo_backfill.php` suite now verifies
retirement: 22 checks, including 140 requests across root/subpath, anonymous/admin,
five retired paths and seven methods; a pre-existing route cannot reactivate the
feature. It also checks menus, retained normal controllers, migration replay and
unchanged historical article/SEO/draft/log data. This is in-process request
acceptance, not physical-device or production acceptance.

Native loopback HTTP acceptance also passed 13 checks: anonymous and authenticated
old pages and the old AI batch POST return 410; the rendered sidebar omits both
links; normal article editor, AI settings, keywords, crawler statistics and
search-engine connections remain reachable. Historical content/job/log snapshots
stay unchanged. No real AI provider request was made.

Related keyword-center, SEO/GEO (65), AI settings, article UTF-8 save (59),
content PATCH (35), admin-layout and frozen-migration regressions passed. The
Core manifest has 355 members, with only the dedicated service removed and the
three edited Core hashes changed; native integrity status is ok.

The authorized update server ran the unchanged V1 contract (4 checks) and this
retirement suite in a new 0700 private clone based on the recorded base plus the
reviewed patch. All passed; the five tested file hashes match this candidate.
This is precommit patch-bound evidence, not formal Gate approval for a future
merge/release commit. Signing test keys stayed in server-only isolated processes;
none were generated or exported on the workstation. Phase 0, a new version and
formal release preparation were not requested or performed.

The private machine reports and patch digest remain in the task handoff. Old implementation reports describe historical releases and are not
instructions to restore the tools in this candidate. Reverting retirement would
restore execution access and needs an explicit Owner decision.

## Knowledge synchronization

This internal candidate document and the documentation index are updated. The V1
knowledge package remains pinned to published Core1.2.90; its optimizer record
describes that released source, where the tools still exist. Its baseline check
passed before the edits (47 facts, 22 public facts, 56 pinned source files).
Do not rewrite published facts to claim this unpublished candidate is deployed.
The next authorized release must refresh the pinned facts and retire the old
optimizer record before rebuilding/importing customer knowledge. No knowledge
package rebuild, import, index, consumer application or production synchronization
is performed here.
