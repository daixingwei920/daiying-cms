# Member collaboration retirement — Issue #9, batch A

Owner approved retirement on 2026-10-23. This branch is an unpublished candidate based on main a7f99ffdd61eace1b997516a2d91376f5f72f602 / Core 1.2.88.

`/admin/member-access` and `/workspace`, including descendants, return 410 with private/no-store for every method before routing. Removed the menu, route handlers, member role/draft services and the two dedicated runtime capabilities. Existing sessions and historical ai_operator assignments cannot reactivate the workspace. Ordinary membership, administrator identity, shared roles, AI and plugin APIs remain.

Published migration 2026_10_20_000001_member_editorial_access.php is immutable. Historical cms_member_roles, roles/capabilities, cms_contents.owner_member_id, content and audit records are retained. Fresh installations still run the historical migration; its seed data is dormant. Do not delete historical authorizations to retire runtime access.

Before edits, git archive captured complete source at a7f99ff. Archive SHA256: 8a9bb31035dab05329cbbfa2f340cc386ae7af382904d269e40cac01ea7b6f0d. Backup is outside Git at work/issue9-source-a7f99ff.tar.gz in the task workspace. It contains repository source, not production data or credentials.

Regression: tests/member_editorial_access.php now exercises all retired paths/methods for anonymous, member, historical operator and administrator identities at root and /cms; verifies unchanged identity, grants, drafts and migration records; checks normal logins and missing retired services. tests/member_editorial_migration.php remains intact for published migration compatibility.

Rollback is a revert of batch A against a retained database. It reactivates the retired collaboration surface and therefore requires explicit Owner acceptance of that effect; never silently restore the feature. No destructive down migration is required.

Historical documentation in member-editorial-access.md describes 1.2.88, not availability in this candidate. No merge, release, deployment or production backup/write has occurred.
