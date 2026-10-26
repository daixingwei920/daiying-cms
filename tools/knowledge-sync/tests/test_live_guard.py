"""Fail closed before any database/provider interaction without matching live approval."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest


class LiveApprovalGuard(unittest.TestCase):
    def reject(self, approval):
        with tempfile.TemporaryDirectory(prefix='knowledge-live-guard-') as temporary:
            root = Path(temporary).resolve()
            (root / '.daiying-knowledge-isolated').write_text('ISOLATED\n')
            setup = root / 'setup.json'
            setup.write_text(json.dumps({'root': str(root / 'site'), 'database': str(root / 'site/site.sqlite')}))
            if approval is not None:
                (root / 'live-provider-approved.json').write_text(json.dumps(approval))
            result = subprocess.run([sys.executable, '-B', str(Path(__file__).with_name('http_consumer.py')),
                                     '--isolation', str(root), '--repository', str(root), '--setup', str(setup),
                                     '--release', str(root / 'not-read.json'), '--live-provider',
                                     '--expected-model', 'approved-test-model'], capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('FileNotFoundError' if approval is None else 'AssertionError', result.stderr)
            self.assertIn('live-provider-approved.json' if approval is None else 'approval.get(', result.stderr)
            self.assertNotIn('PASS ', result.stdout)
            self.assertFalse((root / 'site').exists())
            self.assertFalse((root / 'state').exists())
            self.assertFalse((root / 'reviewed-source').exists())

    def approval(self):
        return {'environment': 'isolated', 'model': 'approved-test-model', 'max_live_requests': 5, 'expires_at': time.time() + 60}

    def test_missing(self): self.reject(None)
    def test_expired(self): self.reject(dict(self.approval(), expires_at=time.time() - 1))
    def test_wrong_model(self): self.reject(dict(self.approval(), model='other-model'))
    def test_wrong_budget(self): self.reject(dict(self.approval(), max_live_requests=100))


if __name__ == '__main__':
    unittest.main(verbosity=2)
