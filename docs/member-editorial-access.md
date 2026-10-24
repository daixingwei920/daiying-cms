> Issue #9 candidate: this feature is retired. See [retirement and retention](member-editorial-retirement.md). The following is historical 1.2.88 documentation.

# Core member registration and editorial access candidate

Core already provides `/register`, `/login`, and a shared member identity in `cms_front_users`. This isolated candidate reuses that identity for a limited editorial workspace. It does not create a second member password store or turn members into backend administrators.

## Owner setup

1. Have the operator register as a normal CMS member. Registration grants no editorial access by itself.
2. The first CMS administrator opens `/admin/member-access` and reviews the role scope shown there.
3. The Owner completes a fresh reauthentication under `/admin/security`, then assigns the `AI 运营` role to the selected active member. Role changes require CSRF validation and are recorded in the audit log.
4. The member logs in through the normal `/login` route and uses `/workspace`.

The role grants exactly `content.own_draft` and `seo.read`. The workspace can create and edit only articles owned by that member while their status is `draft`. It cannot publish, schedule, delete, edit other members' drafts, or access the `/admin` backend. The SEO view at `/workspace/seo` shows keywords and status without write controls. Server-side checks re-read the assignment on every request, so revocation takes effect on an existing member session.

Owner review and publication remain in the existing administrator content flow. Existing content has no member owner and is never claimed by this migration. A newly registered member has no role until the Owner explicitly assigns one.

## Delivery boundary

This is isolated Core source work based on tag `v1.2.87`. It is not installed on the official site and does not authorize a release, upgrade, account creation, or production role change. Before a production release, complete the normal Core Release Gate, migration rehearsal on SQLite and MySQL, UI acceptance under the active theme, and Owner approval. The pinned product knowledge V1 package remains tied to its reviewed source commit and is not updated to claim an unreleased capability.

## 1.2.88 candidate integration acceptance (2026-10-20)

Integrated with the request-driven image/vector execution candidate in a clean worktree, development identity `1.2.88-dev.2`. Published Core remains1.2.87. The original member-access checkout was preserved; ten scoped source files were frozen with hashes before import.

- SQLite and isolated MySQL9.7.1: the new migration upgrades the historical schema once, is repeatable, preserves existing content/identities/password hashes and leaves old content unowned and members unassigned. MySQL native prepares enabled. Both databases pass23 role/workspace checks and10 migration/retention checks.
- The installed server's MySQL5.7 test instance was rejected by the existing Core historical migration minimum8.0.13. This protection was preserved; supported MySQL9.7 was used instead. No claim of MySQL5.7 compatibility or a separate MySQL8.0 run.
- Native isolated site, active official theme1.0.8, browser registration, Owner password reauthentication, grant, own-draft creation/edit/reload, SEO data/read-only display, admin separation and revocation verified.18 native HTTP negative/retention checks supplement browser evidence. The workspace uses Core front layout; enabling a theme does not grant its backend to members.
-15 combined fixture checks verify shared Core member IDs, direct vector/image execution with no Worker, repeated-call safety, other-operator isolation, role revocation preserving normal Logo membership, disabled-account denial and120-second configuration retention. No paid provider calls in this integration run; earlier dev.1 real AI evidence is separate.
- Related Core/plugin regressions passed. Release Gate V1 contract ran on the authorized server using temporary test signing only, not a formal distribution signature or full release approval.

Scope remains isolated. No main merge, release tag, formal signing, publication, production deployment, payment setting changes or refunds. The pinned reviewed product knowledge bundle stays unchanged; this unpublished capability is documented here and in the development knowledge index.
