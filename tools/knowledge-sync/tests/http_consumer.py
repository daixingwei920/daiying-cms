"""Native installed plugin -> Core AI gateway -> selected test provider.

Default uses a deterministic loopback provider. --live-provider additionally requires
an explicit short-lived server-side approval marker and a configured real provider.
Requires the separately provisioned NEW native189 site; never creates a production marker.
"""
import argparse, hashlib, importlib.util, json, os, sqlite3, subprocess, sys, threading, time
from pathlib import Path
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.request import Request, urlopen
from urllib.parse import urlencode

HERE=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(HERE))
from sync import Sync, canonical

args=argparse.ArgumentParser()
args.add_argument('--isolation',required=True);args.add_argument('--repository',required=True);args.add_argument('--setup',required=True);args.add_argument('--release',required=True)
args.add_argument('--live-provider',action='store_true',help='server-only real provider acceptance; requires separate approval marker')
args.add_argument('--expected-model',default='gpt-4.1-mini',help='must match the explicit server-side live approval')
opt=args.parse_args();root=Path(opt.isolation).resolve();repo=Path(opt.repository).resolve()
assert (root/'.daiying-knowledge-isolated').read_text().strip()=='ISOLATED'
setup=json.loads(Path(opt.setup).read_text());site=Path(setup['root']);database=Path(setup['database'])
assert site.is_relative_to(root) and database.is_relative_to(root)
if opt.live_provider:
    approval=json.loads((root/'live-provider-approved.json').read_text())
    assert approval.get('environment')=='isolated' and approval.get('model')==opt.expected_model
    assert approval.get('max_live_requests')==5 and time.time()<approval.get('expires_at',0)
assert hashlib.sha256((site/'content/plugins/official.ai-customer-service/src/Service/KnowledgeRetriever.php').read_bytes()).hexdigest()==json.loads((HERE/'reuse-lock.json').read_text())['sha256']['src/Service/KnowledgeRetriever.php']
model_calls=[]
class Model(BaseHTTPRequestHandler):
    def log_message(self,*a):pass
    def do_POST(self):
        try:
            assert self.path=='/v1/chat/completions'
            data=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
            assert data['model']=='fixture-no-external-model'
            text=data['messages'][-1]['content']
            import re
            match=re.search(r'\nSource: [^\n]+\n(.*?)\nURL:',text,re.S)
            assert match
            model_calls.append({'path':self.path,'context_sha256':hashlib.sha256(text.encode()).hexdigest(),'legacy_or_internal_seen':any(x in text for x in ['只能用Oracle','OLD99','INTERNAL-SECRET'])})
            raw=json.dumps({'id':'isolated-fixture','object':'chat.completion','model':data['model'],'choices':[{'message':{'role':'assistant','content':'[隔离合成提供方] '+match[1]}}],'usage':{'prompt_tokens':1,'completion_tokens':1,'total_tokens':2}},ensure_ascii=False).encode()
            self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(raw)
        except Exception:
            self.send_response(400);self.end_headers();self.wfile.write(b'{"error":"fixture protocol mismatch"}')

def git(path,*cmd):return subprocess.check_output(['git','-C',str(path),*cmd],text=True,stderr=subprocess.DEVNULL).strip()
articles=root/'reviewed-source';articles.mkdir();git(articles,'init','-b','main')
(articles/'plugin.json').write_text('{"version":"1.0.0"}')
(articles/'docs').mkdir();(articles/'docs/guide.md').write_text('Synthetic reviewed tutorial fixture; not official product claims.')
rows=[{'id':'clock','title':'nebula synthetic guide','content':'nebula approved value BLUE47','status':'published','grade':'A','review_status':'reviewed','visibility':'USER-FACING','user_facing':True,'url':'https://fixture.invalid/guide'}]
def commit_rows(message):
    (articles/'articles.json').write_text(canonical(rows));git(articles,'add','.');git(articles,'-c','user.name=Isolated Fixture','-c','user.email=fixture@example.invalid','commit','-m',message)
    return git(articles,'rev-parse','HEAD')
commit_rows('reviewed synthetic source');git(articles,'tag','v1.0.0')
cfg={'environment':'isolated','isolation_root':str(root),'state_dir':str(root/'state'),'max_attempts':3,'retry_delay_seconds':1,
     'sources':[{'id':'cms','repository':str(repo),'github_repository':'daixingwei920/daiying-cms','initial_tag':'v1.2.89','version_file':'config/app.example.php','knowledge_ref':'v1.2.89','watch_ref':'v1.2.89','package_path':'DAIYING_CMS_AI_PRODUCT_KNOWLEDGE_V1.json','include':['system/core','system/migrations','docs']},
                {'id':'tutorial-fixture','repository':str(articles),'github_repository':'fixture/tutorials','initial_tag':'v1.0.0','version_file':'plugin.json','knowledge_ref':'main','watch_ref':'main','articles_path':'articles.json','include':['docs']}],
     'consumers':[{'id':'context-file','kind':'file','destination':str(root/'context')},
                  {'id':'native-customer','kind':'customer_service_sqlite','database':str(database),'plugin_root':str(site/'content/plugins/official.ai-customer-service'),'site_id':setup['site_id'],'core_version':'1.2.89'}]}
(root/'config.json').write_text(json.dumps(cfg,indent=2));engine=Sync(cfg);checks=[];responses=[]
def ck(ok,message):
    assert ok,message
    checks.append(message);(root/'check-progress.json').write_text(json.dumps({'status':'IN_PROGRESS','checks':checks},ensure_ascii=False,indent=2));print('PASS '+message,flush=True)
def ask(question):
    payload=urlencode({'session_id':'isolated-'+str(len(responses)),'message':question}).encode()
    started=time.monotonic()
    with urlopen(Request('http://127.0.0.1:19518/api/ai-customer-service/chat',data=payload),timeout=30 if opt.live_provider else 15) as r:
        result=json.load(r);responses.append({'question':question,'http':r.status,'elapsed_ms':round((time.monotonic()-started)*1000),'result':result})
        (root/'http-attempts.json').write_text(json.dumps({'responses':responses,'live_provider':opt.live_provider},ensure_ascii=False,indent=2));return result
def retrieval_events():
    with sqlite3.connect(database) as d:return d.execute("SELECT COUNT(*) FROM ai_customer_service_logs WHERE event_type='knowledge.retrieve'").fetchone()[0]
def run_jobs(offset=10):
    for _ in range(8):
        if engine.work(now=time.time()+offset) is None:break
def receipt(source):
    key='knowledge_sync.'+hashlib.sha256((setup['site_id']+':'+source).encode()).hexdigest()
    with sqlite3.connect(database) as d:return json.loads(d.execute('SELECT setting_value FROM ai_customer_service_settings WHERE setting_key=?',(key,)).fetchone()[0])
counter=0
def poll():
    global counter
    counter+=1;engine.tick(now=time.time()+counter*86401);run_jobs()

model=None;thread=None
if not opt.live_provider:
    model=ThreadingHTTPServer(('127.0.0.1',19519),Model);thread=threading.Thread(target=model.serve_forever,daemon=True);thread.start()
router=root/'router.php';router.write_text('<?php require '+repr(str(site/'public/index.php'))+';')
log=(root/'http-server.log').open('w');process=subprocess.Popen(['php','-S','127.0.0.1:19518','-t',str(site/'public'),str(router)],stdout=log,stderr=log)
try:
    for _ in range(40):
        try:
            with urlopen('http://127.0.0.1:19518/health',timeout=2) as h:health=json.load(h)
            break
        except OSError:time.sleep(.1)
    ck(health['version']=='1.2.89','Native site runs published189; frozen190 package not installed')
    before=ask('SQLite 安装');ck(before.get('direct_faq') and '只能用Oracle' in before['answer'],'Reproduce old FAQ fast return before managed activation')
    release=json.loads(Path(opt.release).read_text())
    real_event={'action':'published','repository':{'full_name':'daixingwei920/daiying-cms'},'release':{k:release[k] for k in ['id','tag_name','draft','prerelease','published_at']}}
    job=engine.release('cms',real_event);run_jobs()
    ck(engine.db.execute('SELECT status FROM jobs WHERE id=?',(job,)).fetchone()[0]=='succeeded','Actual published189 knowledge event acknowledged by native customer')
    current=ask('SQLite 安装')
    ck(current['grounded'] and 'SQLite' in current['answer'] and 'Oracle' not in current['answer'],'Actual HTTP answer uses admitted public knowledge, not conflicting old FAQ')
    ck(all(x['priority']=='P0' and x['knowledge_version']=='1.2.89' and x['receipt_sha256']==receipt('cms')['snapshot_sha256'] for x in current['sources']),'HTTP citations bind P0,189 and exact active receipt')
    with sqlite3.connect(database) as d:n=d.execute("SELECT COUNT(*) FROM ai_customer_service_knowledge_items WHERE source_type='knowledge_sync' AND enabled=1").fetchone()[0]
    ck(n==22,'Exactly22 public records imported; internal records excluded')
    same=engine.release('cms',real_event);run_jobs();ck(same==job and receipt('cms')['snapshot_sha256']==current['sources'][0]['receipt_sha256'],'Replay leaves the same active customer knowledge batch')
    calls=len(model_calls);events=retrieval_events();unknown=ask('ZXQ9384 frostwombat')
    ck(not unknown['grounded'] and not unknown['sources'] and len(model_calls)==calls and retrieval_events()==events,'Unknown query returns insufficient information without a provider retrieval event')
    event={'action':'published','repository':{'full_name':'fixture/tutorials'},'release':{'id':1,'tag_name':'v1.0.0','draft':False,'prerelease':False,'published_at':'2026-10-25T00:00:00Z'}}
    engine.release('tutorial-fixture',event);run_jobs();a=ask('nebula');ck('BLUE47' in a['answer'] and a['sources'][0]['priority']=='P1','Reviewed synthetic article reaches actual HTTP customer response')
    rows[0]['content']='nebula approved value BLUE48';revision=commit_rows('reviewed correction');poll();a=ask('nebula')
    ck('BLUE48' in a['answer'] and a['sources'][0]['knowledge_revision']==revision,'Polling updates actual response and revision')
    old=receipt('tutorial-fixture');rows[0]['content']='nebula approved value BLUE49';commit_rows('reviewed correction with injected database failure')
    with sqlite3.connect(database) as d:d.execute("CREATE TRIGGER isolated_import_failure BEFORE UPDATE ON ai_customer_service_knowledge_items BEGIN SELECT RAISE(ABORT,'isolated fixture write failure'); END")
    poll();a=ask('nebula');ck('BLUE48' in a['answer'] and receipt('tutorial-fixture')==old,'Failed import preserves last active batch and actual customer answer')
    with sqlite3.connect(database) as d:d.execute('DROP TRIGGER isolated_import_failure')
    run_jobs(offset=60);a=ask('nebula');ck('BLUE49' in a['answer'],'Retry recovers the same pending snapshot into actual customer response')
    rows[0]['status']='pending';commit_rows('withdraw reviewed fixture');poll();calls=len(model_calls);events=retrieval_events();a=ask('nebula')
    ck(not a['grounded'] and not a['sources'] and len(model_calls)==calls and retrieval_events()==events,'Withdrawn knowledge no longer enters answer or provider retrieval events')
    engine.sources['cms']['knowledge_ref']='HEAD' if opt.live_provider else 'origin/main'
    changed=dict(real_event);changed['release']=dict(real_event['release'],id=999999991)
    rejected=engine.release('cms',changed);run_jobs()
    ck(engine.db.execute('SELECT status FROM jobs WHERE id=?',(rejected,)).fetchone()[0]=='needs_review','Unpublished190 knowledge is rejected for the released189 customer')
    if opt.live_provider:
        ck(receipt('cms')['version']=='1.2.89' and receipt('cms')['snapshot_sha256']==current['sources'][0]['receipt_sha256'],'Rejected candidate does not replace current customer knowledge')
        generated=[r for r in responses if r['result'].get('ok') and r['result'].get('grounded') and not r['result'].get('direct_faq')]
        ck(len(generated)==5 and retrieval_events()==5,'Five actual native provider-success responses, with no mock or extra successful generation')
        ck(not any(any(x in r['result']['answer'] for x in ['只能用Oracle','OLD99','INTERNAL-SECRET']) for r in generated),'Conflicting legacy and internal markers are absent from live answers')
    else:
        ck(ask('SQLite 安装')['sources'][0]['knowledge_version']=='1.2.89','Rejected candidate does not replace current customer knowledge')
        ck(not any(c['legacy_or_internal_seen'] for c in model_calls),'No conflicting legacy or INTERNAL fixture data reached provider context')
    output={'status':'PASS','checks':checks,'responses':responses,'model_protocol_calls':model_calls,'model_type':('configured external HTTPS '+opt.expected_model+'; native Core transport; no mock') if opt.live_provider else 'deterministic loopback protocol fixture; NOT a live paid LLM','native_market_install':True,'production_writes':0,'frozen190_installed':False,'live_provider_success_responses':len(generated) if opt.live_provider else 0,'token_billing_acceptance':False}
    (root/'http-acceptance.json').write_text(json.dumps(output,ensure_ascii=False,indent=2));engine.report();print(json.dumps({'status':'PASS','checks':len(checks)}))
finally:
    process.terminate();process.wait(timeout=10);log.close()
    if model is not None:model.shutdown();model.server_close();thread.join(timeout=5)
    engine.db.close()
