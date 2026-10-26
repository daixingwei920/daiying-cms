"""Real reviewed corpus plus adversarial authority/unknown/withdrawal cases; no model."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parents[1]
ROOT = HERE.parents[1]
PLUGIN = os.environ.get('KNOWLEDGE_PLUGIN_ROOT')
QUESTIONS = [
 ('15.thirdparty', '服务条款是否保证百度网盘永久可用'),
 ('85.install', '餐厅外卖插件怎么安装'),
 ('85.menu', '餐厅菜单识别能自动上架吗'),
 ('82.sale', '圣城授权多少钱，包含终身免费更新吗'),
 ('82.compat', '圣城3.3.9能安装到Core1.2.89吗'),
 ('86.sale', '餐厅外卖授权多少钱，是无限站点吗'),
 ('88.sale', '来访自动收录授权多少钱，需要什么Core版本'),
]

@unittest.skipUnless(PLUGIN, 'locked candidate plugin required')
class Relevance(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='knowledge-relevance-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()
        (self.root/'.daiying-knowledge-isolated').write_text('ISOLATED')
        self.db = self.root/'site.sqlite'
        Path(str(self.db)+'.isolated.json').write_text(json.dumps({'environment':'isolated','site_id':'test-site'}))
        self.php('init')
        package = json.loads(subprocess.check_output(['git','show','v1.2.89:DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1.json'],cwd=ROOT))
        self.core = [self.row(r['id'],r['capability'],r['description'],r['topic'],'P0') for r in package['records'] if r['enabled_for_customer_service']]
        articles = json.loads((ROOT/'docs/knowledge/2026-10-25-reviewed-site-excerpts.json').read_text())
        self.web = [self.row('article.'+r['id'],r['title'],r['content'],r['topic'],'P1') for r in articles]
        self.put('cms', self.core)
        self.put('reviewed-website', self.web)

    def row(self, key, title, content, topic, priority):
        return {'id':key,'title':title,'content':content,'topic':topic,'priority':priority,'visibility':'USER-FACING',
                'content_hash':hashlib.sha256(content.encode()).hexdigest(),'source_path':'reviewed-fixture.json','url':'https://fixture.invalid/'+key}

    def php(self, action, *args):
        return json.loads(subprocess.check_output(['php',str(HERE/'tests/plugin_fixture.php'),action,str(self.db),PLUGIN,'test-site',*args],text=True))

    def query(self, question):
        return self.php('query-version',question,'1.2.89')

    def put(self, source, rows):
        snapshot = {'environment':'isolated','source':source,'version':'1.2.89','tag':'v1.2.89',
                    'commit':'3dcc700174abd1b9a7d2959a09881f247026de1c','knowledge_commit':'a'*40,'records':rows}
        return json.loads(subprocess.check_output(['php',str(HERE/'customer_service.php'),str(self.db),PLUGIN,'test-site','1.2.89'],input=json.dumps(snapshot,ensure_ascii=False),text=True))

    def test_seven_natural_questions(self):
        for key, question in QUESTIONS:
            with self.subTest(question=question):
                found = self.query(question)
                self.assertIn('article.'+key,{r['knowledge_id'] for r in found})
                self.assertEqual({'P1'},{r['priority'] for r in found})
                self.assertTrue(all(not any(k.startswith('_') for k in r) for r in found))

    def test_related_short_p0_wins_even_against_verbose_conflict(self):
        official = self.row('restaurant.rule','餐厅官方规则','餐厅仅单站。','餐厅','P0')
        self.put('official-rules',[official])
        question = '餐厅外卖授权多少钱，是无限站点吗'
        found = self.query(question)
        self.assertEqual(['restaurant.rule'],[r['knowledge_id'] for r in found])
        self.assertEqual('P0',found[0]['priority'])

    def test_official_body_subject_preserves_authority(self):
        official = self.row('restaurant.body','Official rule','餐厅外卖仅单站。','Official','P0')
        self.put('official-rules',[official])
        self.assertEqual(['restaurant.body'],[r['knowledge_id'] for r in self.query('餐厅外卖授权多少钱，是无限站点吗')])

    def test_p1_wins_over_related_p2(self):
        other = self.row('unreviewed-price','餐厅外卖授权价格','餐厅外卖授权多少钱，1元无限站。','餐厅外卖','P2')
        self.put('ordinary',[other])
        found = self.query('餐厅外卖授权多少钱，是无限站点吗')
        self.assertTrue(found)
        self.assertEqual({'P1'},{r['priority'] for r in found})

    def test_cross_product_references_do_not_retarget_p1(self):
        for question, prefix in [('圣城授权多少钱，包含终身免费更新吗','article.82.'),('来访自动收录授权多少钱，需要什么Core版本','article.88.')]:
            found = self.query(question)
            self.assertTrue(found)
            self.assertTrue(all(r['knowledge_id'].startswith(prefix) for r in found))

    def test_unknown_noise_and_core_regression(self):
        for question in ['ZXQ9384 frostwombat','是否可以支持星际飞船量子保险','你好']:
            self.assertEqual([],self.query(question))
        found = self.query('SQLite 安装')
        self.assertTrue(found)
        self.assertEqual({'P0'},{r['priority'] for r in found})

    def test_withdrawal_then_reimport(self):
        self.php('noise')  # Old exact FAQ and 510 competing legacy rows remain inadmissible.
        self.put('reviewed-website',[])
        for key, question in QUESTIONS:
            self.assertEqual([],self.query(question),question)
        self.assertTrue(self.query('SQLite 安装'))
        self.put('reviewed-website',self.web)
        self.assertIn('article.88.sale',{r['knowledge_id']for r in self.query(QUESTIONS[-1][1])})

    def test_withdrawn_official_allows_related_p1(self):
        self.put('official-rules',[self.row('holy.rule','圣城官方授权','圣城单站。','圣城','P0')])
        question=QUESTIONS[3][1]
        self.assertEqual({'P0'},{r['priority']for r in self.query(question)})
        self.put('official-rules',[])
        self.assertEqual({'P1'},{r['priority']for r in self.query(question)})

    def test_numeric_scope_does_not_change_public_result_shape(self):
        self.put('numeric',[self.row('numeric.rule','12345','12345 单站。','12345','P0')])
        found=self.query('12345')
        self.assertEqual(['numeric.rule'],[r['knowledge_id']for r in found])

if __name__ == '__main__':
    unittest.main(verbosity=2)
