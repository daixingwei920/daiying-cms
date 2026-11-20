import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

spec = importlib.util.spec_from_file_location(
    "sync_gitee", Path(__file__).resolve().parents[1] / "scripts" / "sync_gitee.py")
sync_gitee = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync_gitee)


class GiteeSyncTests(unittest.TestCase):
    def test_only_published_stable_release_tags_are_selected(self):
        def release(tag, **flags):
            return dict(tag_name=tag, draft=False, prerelease=False, **flags)
        pages = [[release("v1.2.100"), release("v1.2.100-rc.1"), release("v1.2.22-raw")],
                 [dict(tag_name="v1.2.101", draft=True, prerelease=False),
                  dict(tag_name="v1.2.102", draft=False, prerelease=True), release("v1.2.99")]]
        self.assertEqual(sync_gitee.stable_tags(pages), ["v1.2.100", "v1.2.99"])
        with self.assertRaises(RuntimeError):
            sync_gitee.stable_tags([[]])

    def test_git_refs_are_one_way_exact_and_conflicts_are_atomic(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source, target = root / "source", root / "target.git"
            source.mkdir()
            def git(repo, *args):
                return sync_gitee.git(repo, *args).strip()
            git(source, "init", "--initial-branch=main")
            git(source, "config", "user.name", "Synthetic mirror test")
            git(source, "config", "user.email", "mirror-test@example.invalid")
            (source / "README.md").write_text("Synthetic test data\n")
            git(source, "add", "README.md")
            git(source, "commit", "-m", "Synthetic baseline")
            git(source, "tag", "-a", "v1.2.100", "-m", "Synthetic stable")
            git(source, "tag", "v1.2.101")  # Semver alone does not make a formal release.
            git(source, "tag", "v1.2.101-rc.1")
            git(source, "branch", "development")
            target.mkdir()
            git(target, "init", "--bare", "--initial-branch=main")
            before = git(source, "show-ref", "--dereference")
            summary = sync_gitee.sync(str(source), str(target), ["v1.2.100"])
            self.assertEqual(summary["mismatches"], [])
            refs = sync_gitee.parse_refs(git(target, "show-ref", "--dereference"))
            self.assertEqual(set(refs), {"refs/heads/main", "refs/tags/v1.2.100", "refs/tags/v1.2.100^{}"})
            self.assertEqual(refs["refs/tags/v1.2.100"], git(source, "rev-parse", "v1.2.100"))
            self.assertEqual(before, git(source, "show-ref", "--dereference"))
            self.assertEqual(sync_gitee.sync(str(source), str(target), ["v1.2.100"], push=False)["mode"],
                             "read-only-check")
            original_main = refs["refs/heads/main"]
            (source / "README.md").write_text("Synthetic next commit\n")
            git(source, "commit", "-am", "Synthetic next")
            git(source, "tag", "-f", "v1.2.100")
            with self.assertRaises(RuntimeError):
                sync_gitee.sync(str(source), str(target), ["v1.2.100"])
            self.assertEqual(git(target, "rev-parse", "main"), original_main)
            self.assertEqual(git(target, "rev-parse", "v1.2.100"), refs["refs/tags/v1.2.100"])
            git(source, "tag", "-f", "v1.2.100", refs["refs/tags/v1.2.100"])
            sync_gitee.sync(str(source), str(target), ["v1.2.100"])
            self.assertEqual(git(target, "rev-parse", "main"), git(source, "rev-parse", "main"))
            git(source, "reset", "--hard", original_main)
            with self.assertRaises(RuntimeError):
                sync_gitee.sync(str(source), str(target), ["v1.2.100"])
            self.assertNotEqual(git(target, "rev-parse", "main"), original_main)


if __name__ == "__main__":
    unittest.main()
