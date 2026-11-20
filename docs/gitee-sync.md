# GitHub to Gitee source synchronization

GitHub `daixingwei920/daiying-cms` is the source of truth. Gitee
`daiyingcms/daiying-cms` receives only `main` and tags attached to published,
non-draft, non-prerelease GitHub Releases whose names match `vMAJOR.MINOR.PATCH`.
A matching tag name without a published stable Release is not synchronized.

The GitHub Actions workflow runs on `main` pushes, stable Release publication
or promotion, hourly reconciliation, and manual dispatch. It fetches the current source, pushes explicit
refs atomically, and reads back all selected refs to compare both tag objects and
their peeled commit SHA. It never force-pushes, prunes, deletes remote refs, or
pushes to GitHub. Conflicting Gitee history fails the entire push.

## Credential setup

Create a Gitee **repository-level** private token limited to
`daiyingcms/daiying-cms`, with `projects` and any platform-required identity scope
only. Store it as the GitHub repository Actions secret `GITEE_SYNC_TOKEN`.
Do not put the token in source, remote URLs, logs, chat, or local files. The token
is passed through an ephemeral askpass helper only to the fixed Gitee push URL;
the helper itself contains no credential. GitHub's token has `contents: read`.
Token creation and storing it in GitHub require the owner's credential approval.
Renew an expiring token by replacing that same secret before expiry.

Until the secret is present and a real Actions run passes, treat automatic
synchronization as **not enabled/accepted**. Use the Actions run result to detect
failures. After correcting a failed run, rerun or dispatch this workflow manually.
Hourly reconciliation also catches stable releases whose immediate event was
suppressed by GitHub (for example a publisher using `GITHUB_TOKEN`), or whose
historic tag predates the workflow. It applies the same published-release filter.

## Validation and scope

Run `python3 -m unittest discover -s .github/tests -p 'test_sync_gitee.py' -v`.
Run `python3 .github/scripts/sync_gitee.py --check` for a read-only comparison
using the current published GitHub Release list. No Gitee secret is needed for
this check. A real Actions run must also verify the configured credential.

This changes repository operations only: no Core runtime, version, migration,
license, signing trust, release archive, or production site changes. Release
attachments, Issues and Pull Requests are not copied. The Core Release Gate
contract test generates asymmetric test keys and must not be run locally under
the repository's key policy; this task does not build or publish a Core release.

Knowledge synchronization status: product facts and governed V1 exports are
unchanged because no product/API behavior changes. This operations document is
the setup reference; no knowledge build, import, indexing or consumer deployment
is performed by this task.
