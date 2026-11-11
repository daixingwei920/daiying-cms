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
        self.assertEqual(self.package['candidate_evidence']['candidate_version'], '1.2.98')
        self.assertEqual('当前分支知识源是未发布候选' in text,
                         self.package['candidate_evidence']['status'] == 'UNPUBLISHED')
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
        for rid in ['rest.foundation_media_contract', 'payment.configured_redirect_contract', 'payment.provider_settings_sqlite_save', 'storage.plugin_data_scope_index', 'maintenance.scheduler_connection_readiness', 'admin.compact_workspaces', 'admin.update_flow_continuation', 'admin.starship_original_palette', 'admin.command_bridge', 'seo.plugin_sitemap_registration']:
            record = next(r for r in self.package['records'] if r['id'] == rid)
            self.assertEqual(record['visibility'], 'ADMIN_DEVELOPER')
            self.assertFalse(record['enabled_for_customer_service'])
            self.assertNotIn(rid, exported)

    def test_published_release_evidence_is_preserved(self):
        published193 = json.loads(subprocess.check_output(['git', 'show', 'fca2464fbd879956532839a345a8362c282b3695:' + product.NAME + '.json'], cwd=ROOT, text=True))
        published194 = json.loads(subprocess.check_output(['git', 'show', '75ad39cb12008e1aa8195f5dfec5620c128e3393:' + product.NAME + '.json'], cwd=ROOT, text=True))
        published195 = json.loads(subprocess.check_output(['git', 'show', '8964902b11a07d6623037e14c01974c08ebe23e8:' + product.NAME + '.json'], cwd=ROOT, text=True))
        self.assertEqual(self.package['published_195_acceptance_evidence'], published195['candidate_evidence'])
        published196 = json.loads(subprocess.check_output(['git', 'show', '05d0f951bfce396deb2e19b0796a06bbe8484811:' + product.NAME + '.json'], cwd=ROOT, text=True))
        self.assertEqual(self.package['published_196_acceptance_evidence'], published196['candidate_evidence'])
        evidence194 = self.package['release_evidence']
        if evidence194['version'] == '1.2.98':
            self.assertEqual(evidence194['commit'], subprocess.check_output(['git', 'rev-parse', 'v1.2.98^{commit}'], cwd=ROOT, text=True).strip())
            evidence194 = evidence194['previous_stable']
        self.assertEqual(evidence194['version'], '1.2.97')
        self.assertEqual(evidence194['commit'], subprocess.check_output(['git', 'rev-parse', 'v1.2.97^{commit}'], cwd=ROOT, text=True).strip())
        evidence194 = evidence194['previous_stable']
        self.assertEqual(evidence194, published196['release_evidence'])
        if evidence194['version'] == '1.2.96':
            self.assertEqual(evidence194['previous_stable'], published195['release_evidence'])
            evidence194 = evidence194['previous_stable']
        if evidence194['version'] == '1.2.95':
            evidence194 = evidence194['previous_stable']
        self.assertEqual(evidence194, published194['release_evidence'])
        self.assertEqual(self.package['published_194_acceptance_evidence'], published194['candidate_evidence'])
        evidence193 = evidence194['previous_stable']
        self.assertEqual(evidence193, published193['release_evidence'])
        self.assertEqual(self.package['published_193_acceptance_evidence'], published193['candidate_evidence'])
        baseline = json.loads(subprocess.check_output(['git', 'show', 'b8edbdce6c9008ddb50facfebb6c2617cde47b9c:' + product.NAME + '.json'], cwd=ROOT, text=True))
        self.assertEqual(evidence193['previous_stable']['previous_stable']['previous_stable'], baseline['release_evidence'])
        published = json.loads(subprocess.check_output(['git', 'show', '9a29ee2f981ea6353fc7f91abe9b983e0f1c9680:' + product.NAME + '.json'], cwd=ROOT, text=True))
        self.assertEqual(evidence193['previous_stable']['previous_stable'], published['release_evidence'])
        self.assertEqual(self.package['published_191_candidate_evidence'], published['candidate_evidence'])
        published192 = json.loads(subprocess.check_output(['git', 'show', '24e1b3cee01cbf5adf31794d1bbe5f7826054468:' + product.NAME + '.json'], cwd=ROOT, text=True))
        self.assertEqual(evidence193['previous_stable'], published192['release_evidence'])
        self.assertEqual(evidence193['commit'], subprocess.check_output(['git', 'rev-parse', 'v1.2.93^{commit}'], cwd=ROOT, text=True).strip())
        self.assertEqual(self.package['source_commit'], self.package['candidate_evidence']['source_commit'])
        if self.package['candidate_evidence']['status'] == 'PUBLISHED':
            self.assertEqual(self.package['source_tag'], 'v1.2.98')
            self.assertEqual(self.package['source_commit'], self.package['release_evidence']['commit'])
        else:
            self.assertEqual(self.package['source_tag'], self.package['source_commit'])
            self.assertNotEqual(self.package['source_commit'], evidence193['commit'])
        self.assertEqual(self.package['published_192_acceptance_evidence'], published192['candidate_evidence'])
        self.assertEqual(self.package['published_192_acceptance_evidence']['status'], 'PUBLISHED')
        self.assertEqual(self.package['published_baseline_evidence'], baseline['candidate_evidence'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
