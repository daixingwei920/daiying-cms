"""Strict provenance and unpublished projection regression; no consumers or network."""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('product_knowledge', ROOT / 'scripts/product_knowledge.py')
product = importlib.util.module_from_spec(spec)
spec.loader.exec_module(product)


class KnowledgeProvenance(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.package = json.loads((ROOT / (product.NAME + '.json')).read_text())
        cls.temp = tempfile.TemporaryDirectory(prefix='cms-knowledge-provenance-')
        cls.root = Path(cls.temp.name) / 'source'
        subprocess.run(['git', 'clone', '--quiet', '--shared', '--no-checkout', str(ROOT), str(cls.root)], check=True)
        subprocess.run(['git', 'checkout', '--quiet', '--detach', cls.package['source_commit']], cwd=cls.root, check=True)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def test_pinned_source_validates_with_strict_worktree_check(self):
        self.assertGreater(product.validate(self.package, root=self.root), 0)

    def test_worktree_source_drift_still_fails_closed(self):
        path = self.root / 'config/app.example.php'
        before = path.read_bytes()
        try:
            path.write_bytes(before + b'\n// unauthorized drift\n')
            with self.assertRaisesRegex(ValueError, 'source drift'):
                product.validate(self.package, root=self.root)
        finally:
            path.write_bytes(before)

    def test_wrong_evidence_hash_is_rejected(self):
        package = copy.deepcopy(self.package)
        package['records'][0]['source_evidence'][0]['sha256'] = '0' * 64
        with self.assertRaisesRegex(ValueError, 'source hash'):
            product.validate(package, root=self.root)

    def test_untrusted_source_path_is_rejected(self):
        package = copy.deepcopy(self.package)
        package['records'][0]['source_evidence'][0]['path'] = '../secrets/private'
        with self.assertRaisesRegex(ValueError, 'unsafe evidence path'):
            product.validate(package, root=self.root)

    def test_mismatched_source_ref_is_rejected(self):
        package = copy.deepcopy(self.package)
        package['source_tag'] = 'v1.2.90'
        with self.assertRaisesRegex(ValueError, 'tag/commit mismatch'):
            product.validate(package, root=self.root)

    def test_internal_record_cannot_be_exported(self):
        package = copy.deepcopy(self.package)
        record = next(r for r in package['records'] if r['visibility'] != 'USER-FACING')
        record['enabled_for_customer_service'] = True
        with self.assertRaisesRegex(ValueError, 'public eligibility'):
            product.validate(package, root=self.root)

    def test_retired_optimizer_is_not_current_customer_guidance(self):
        record = next(r for r in self.package['records'] if r['id'] == 'ai.historical_optimizer')
        self.assertFalse(record['enabled_for_customer_service'])
        self.assertEqual(record['implementation_status'], 'removed')
        self.assertNotIn(record['id'], {r['id'] for r in product.public_records(self.package)})

    def test_publication_and_unpublished_copy_have_correct_warning(self):
        text = product.derived(self.package)[product.NAME + '.md']
        self.assertEqual(self.package['candidate_evidence']['status'], 'PUBLISHED')
        self.assertNotIn('当前分支知识源是未发布候选', text)
        published = copy.deepcopy(self.package)
        published['candidate_evidence']['status'] = 'PUBLISHED'
        self.assertNotIn('当前分支知识源是未发布候选', product.derived(published)[product.NAME + '.md'])
        pending = copy.deepcopy(self.package)
        pending['candidate_evidence']['status'] = 'UNPUBLISHED'
        pending_text = product.derived(pending)[product.NAME + '.md']
        self.assertIn('不代表生产可用', pending_text)
        self.assertIn(pending['candidate_evidence']['runtime_report'], pending_text)
        self.assertNotIn('候选运行证据见 docs/issue9-delivery.md', pending_text)

    def test_foundation_payment_contracts_remain_developer_only(self):
        exported = {r['id'] for r in product.public_records(self.package)}
        for rid in ['rest.foundation_media_contract', 'payment.configured_redirect_contract', 'payment.provider_settings_sqlite_save', 'storage.plugin_data_scope_index']:
            record = next(r for r in self.package['records'] if r['id'] == rid)
            self.assertEqual(record['visibility'], 'ADMIN_DEVELOPER')
            self.assertFalse(record['enabled_for_customer_service'])
            self.assertNotIn(rid, exported)

    def test_published_release_evidence_is_preserved(self):
        baseline = json.loads(subprocess.check_output(['git', 'show', 'b8edbdce6c9008ddb50facfebb6c2617cde47b9c:' + product.NAME + '.json'], cwd=ROOT, text=True))
        self.assertEqual(self.package['release_evidence']['previous_stable']['previous_stable'], baseline['release_evidence'])
        published = json.loads(subprocess.check_output(['git', 'show', '9a29ee2f981ea6353fc7f91abe9b983e0f1c9680:' + product.NAME + '.json'], cwd=ROOT, text=True))
        self.assertEqual(self.package['release_evidence']['previous_stable'], published['release_evidence'])
        self.assertEqual(self.package['published_191_candidate_evidence'], published['candidate_evidence'])
        self.assertEqual(self.package['release_evidence']['commit'], subprocess.check_output(['git', 'rev-parse', 'v1.2.92^{commit}'], cwd=ROOT, text=True).strip())
        self.assertEqual(self.package['source_commit'], self.package['release_evidence']['commit'])
        self.assertEqual(self.package['source_tag'], 'v1.2.92')
        self.assertEqual(self.package['published_baseline_evidence'], baseline['candidate_evidence'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
