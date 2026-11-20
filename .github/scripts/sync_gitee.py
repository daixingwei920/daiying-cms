#!/usr/bin/env python3
"""Push only main and published stable-release tags to the Gitee mirror."""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

REPOSITORY = "daixingwei920/daiying-cms"
SOURCE = f"https://github.com/{REPOSITORY}.git"
TARGET = "https://gitee.com/daiyingcms/daiying-cms.git"
PUSH_TARGET = "https://daiyingcms@gitee.com/daiyingcms/daiying-cms.git"


def stable_tags(pages):
    tags = {
        release["tag_name"]
        for page in pages
        for release in page
        if release.get("draft") is False
        and release.get("prerelease") is False
        and re.fullmatch(r"v[0-9]+\.[0-9]+\.[0-9]+", release.get("tag_name", ""))
    }
    if not tags:
        raise RuntimeError("No published stable-release tags; refusing to sync")
    return sorted(tags)


def git(repo, *args, auth=None):
    env = dict(os.environ, GIT_TERMINAL_PROMPT="0")
    # GitHub credentials are only used by gh, never forwarded to Git/Gitee.
    for key in ("GH_TOKEN", "GITHUB_TOKEN", "GITEE_SYNC_TOKEN", "GIT_ASKPASS"):
        env.pop(key, None)
    if auth:
        env.update(auth)
    result = subprocess.run(
        ["git", "-c", "credential.helper=", "-c", "credential.useHttpPath=true",
         "-C", str(repo), *args],
        env=env, capture_output=True, text=True, timeout=900,
    )
    if result.returncode:
        raise RuntimeError(result.stderr.strip() or "Git command failed")
    return result.stdout


def parse_refs(text):
    return {ref: sha for sha, ref in (line.split() for line in text.splitlines())}


def sync(source, target, tags, *, push=True, push_target=None, token=None):
    if not tags or any(not re.fullmatch(r"v[0-9]+\.[0-9]+\.[0-9]+", t) for t in tags):
        raise RuntimeError("Invalid stable-release tag selection")
    refs = ["refs/heads/main", *[f"refs/tags/{tag}" for tag in tags]]
    with tempfile.TemporaryDirectory(prefix="daiying-gitee-sync-") as directory:
        root = Path(directory)
        repo = root / "mirror.git"
        repo.mkdir()
        git(repo, "init", "--bare", "--initial-branch=main")
        git(repo, "fetch", "--no-tags", source, *[f"{ref}:{ref}" for ref in refs])
        expected = parse_refs(git(repo, "show-ref", "--dereference"))
        if push:
            auth = None
            if token:
                askpass = root / "askpass.py"
                askpass.write_text(
                    "#!/usr/bin/env python3\nimport os, sys\n"
                    "if not sys.argv[1].startswith(\"Password for 'https://daiyingcms@gitee.com/daiyingcms/daiying-cms.git\"):\n"
                    "    sys.exit(1)\n"
                    "print(os.environ['GITEE_SYNC_TOKEN'])\n"
                )
                askpass.chmod(0o700)
                auth = {"GIT_ASKPASS": str(askpass), "GITEE_SYNC_TOKEN": token}
            # No force, mirror, pruning or deletions. Any conflicting ref rejects
            # the entire atomic push instead of rewriting Gitee history.
            git(repo, "push", "--atomic", push_target or target,
                *[f"{ref}:{ref}" for ref in refs], auth=auth)
        actual = parse_refs(git(repo, "ls-remote", target, *refs, "refs/tags/*^{}"))
        mismatches = [ref for ref, sha in expected.items() if actual.get(ref) != sha]
        summary = {"main_sha": expected["refs/heads/main"],
                   "stable_tag_count": len(tags), "mismatches": mismatches,
                   "mode": "push-and-verify" if push else "read-only-check"}
        if mismatches:
            raise RuntimeError("Gitee SHA mismatch: " + ", ".join(mismatches))
        return summary


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="compare only; do not push")
    args = parser.parse_args()
    if os.environ.get("GITHUB_REPOSITORY", REPOSITORY) != REPOSITORY:
        raise RuntimeError("This workflow is restricted to the official source repository")
    token = os.environ.get("GITEE_SYNC_TOKEN")
    if not args.check and not token:
        raise RuntimeError("Missing repository secret GITEE_SYNC_TOKEN")
    api_env = dict(os.environ)
    api_env.pop("GITEE_SYNC_TOKEN", None)
    pages = json.loads(subprocess.check_output(
        ["gh", "api", "--paginate", "--slurp", f"repos/{REPOSITORY}/releases?per_page=100"],
        env=api_env, text=True, timeout=120,
    ))
    print(json.dumps(sync(SOURCE, TARGET, stable_tags(pages), push=not args.check,
                          push_target=PUSH_TARGET, token=token), indent=2))


if __name__ == "__main__":
    main()
