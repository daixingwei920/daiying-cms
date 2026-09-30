#!/usr/bin/env bash
set -euo pipefail

release_root() {
  local script_dir
  script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  cd "${script_dir}/../.." >/dev/null
  pwd
}

release_fail() {
  printf 'FAIL-CLOSED: %s\n' "$*" >&2
  exit 1
}

release_require_clean_worktree() {
  local root="$1"
  local status
  status="$(cd "$root" && git status --porcelain)"
  if [[ -n "$status" ]]; then
    printf '%s\n' "$status" >&2
    release_fail "worktree is not clean"
  fi
}

release_resolve_commit() {
  local root="$1"
  local commit="${2:-HEAD}"
  cd "$root" && git rev-parse "${commit}^{commit}"
}

release_detect_version() {
  local root="$1"
  local commit="$2"
  cd "$root" && git show "${commit}:config/app.example.php" \
    | php -r '$s=stream_get_contents(STDIN); if (preg_match("/[\"'\'']version[\"'\'']\s*=>\s*[\"'\'']([^\"'\'']+)[\"'\'']/", $s, $m)) { echo $m[1], "\n"; exit(0); } exit(1);'
}

release_require_origin_main_commit() {
  local root="$1"
  local commit="$2"
  cd "$root" && git fetch origin main --prune >/dev/null
  local remote
  remote="$(cd "$root" && git rev-parse origin/main)"
  [[ "$commit" == "$remote" ]] || release_fail "commit ${commit} is not current origin/main ${remote}"
}

release_require_remote_ref() {
  local root="$1"
  local commit="$2"
  local refs
  refs="$(cd "$root" && git branch -r --contains "$commit" --format='%(refname:short)' || true)"
  if [[ -z "$refs" ]]; then
    refs="$(cd "$root" && git tag --contains "$commit" || true)"
  fi
  [[ -n "$refs" ]] || release_fail "commit ${commit} has no local remote branch/tag reference"
}

release_assert_version_tag_absent() {
  local root="$1"
  local version="$2"
  if cd "$root" && git ls-remote --exit-code --tags origin "v${version}" >/dev/null 2>&1; then
    release_fail "remote tag v${version} already exists"
  fi
}

