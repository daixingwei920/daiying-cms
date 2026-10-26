"""Native isolated upgrade acceptance: unchanged real corpus, authority and withdrawal.
Paid calls require a separate, explicitly authorized server-side control script.
"""
import argparse, hashlib, json, re, socket, sqlite3, subprocess, sys, time
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import Request, urlopen

opt=argparse.ArgumentParser()
opt.add_argument('--root',required=True)
opt.add_argument('--live-control',required=True)
args=opt.parse_args();base=Path(args.root).resolve();control=Path(args.live_control).resolve()
assert re.fullmatch(r'/root/daiying-relevance-fix-20261025-[A-Za-z0-9]+',str(base))
assert control.parent==base and control.name=='live-control.php'
root=base/'isolation';site=root/'site';db=site/'storage/database/cms.sqlite';tools=Path(__file__).resolve().parents[1]
assert (root/'.daiying-knowledge-isolated').read_text().strip()=='ISOLATED'
sys.path.insert(0,str(tools))
from sync import Sync
plugin=site/'content/plugins/official.ai-customer-service';sid=json.loads(Path(str(db)+'.isolated.json').read_text())['site_id']
assert json.loads((plugin/'plugin.json').read_text())['version']=='1.1.1-dev.2'
lock=json.loads((tools/'reuse-lock.json').read_text())
for f,h in lock['sha256'].items():assert hashlib.sha256((plugin/f).read_bytes()).hexdigest()==h
with socket.socket()as sock:
 sock.settimeout(1);assert sock.connect_ex(('127.0.0.1',19518))!=0
repo=base/'reviewed-source';path=repo/'docs/knowledge-reviewed/site-pages.json';rows=json.loads(path.read_text())
cfg={'environment':'isolated','isolation_root':str(root),'state_dir':str(root/'state'),'max_attempts':3,'retry_delay_seconds':1,
 'sources':[{'id':'reviewed-website','repository':str(repo),'github_repository':'daixingwei920/daiying-cms','initial_tag':'v1.2.89','version_file':'config/app.example.php','knowledge_ref':'review/relevance-verification-189','watch_ref':'review/relevance-verification-189','articles_path':'docs/knowledge-reviewed/site-pages.json','include':['docs/knowledge-reviewed']}],
 'consumers':[{'id':'context','kind':'file','destination':str(root/'context')},{'id':'customer','kind':'customer_service_sqlite','database':str(db),'plugin_root':str(plugin),'site_id':sid,'core_version':'1.2.89'}]}
(root/'config.json').write_text(json.dumps(cfg,indent=2));engine=Sync(cfg);checks=[];responses=[];process=None;log=None;calls=0
questions=[('15.thirdparty','服务条款是否保证百度网盘永久可用'),('85.install','餐厅外卖插件怎么安装'),('85.menu','餐厅菜单识别能自动上架吗'),('82.sale','圣城授权多少钱，包含终身免费更新吗'),('82.compat','圣城3.3.9能安装到Core1.2.89吗'),('86.sale','餐厅外卖授权多少钱，是无限站点吗'),('88.sale','来访自动收录授权多少钱，需要什么Core版本')]
def check(ok,message):
 if not ok:raise RuntimeError(message)
 checks.append(message);(base/'progress.json').write_text(json.dumps({'status':'IN_PROGRESS','checks':checks,'responses':responses},ensure_ascii=False,indent=2));print('PASS '+message,flush=True)
def query(q):return json.loads(subprocess.check_output(['php',str(tools/'tests/plugin_fixture.php'),'query-version',str(db),str(plugin),sid,q,'1.2.89'],text=True))
def work():
 for _ in range(10):
  if engine.work(now=time.time()+30)is None:break
def receipt(source):
 key='knowledge_sync.'+hashlib.sha256((sid+':'+source).encode()).hexdigest()
 with sqlite3.connect(db)as p:r=p.execute('SELECT setting_value FROM ai_customer_service_settings WHERE setting_key=?',(key,)).fetchone()
 return json.loads(r[0])if r else None
def events():
 with sqlite3.connect(db)as p:return p.execute("SELECT COUNT(*) FROM ai_customer_service_logs WHERE event_type='knowledge.retrieve'").fetchone()[0]
def ask(question,expected_model):
 global calls
 if expected_model:
  assert calls<8;calls+=1
 else:assert query(question)==[]  # Verify the local no-provider branch before posting.
 started=time.monotonic()
 with urlopen(Request('http://127.0.0.1:19518/api/ai-customer-service/chat',data=urlencode({'session_id':'relevance-'+str(len(responses)),'message':question}).encode()),timeout=30)as r:
  result=json.load(r);responses.append({'question':question,'http':r.status,'elapsed_ms':round((time.monotonic()-started)*1000),'result':result})
 (base/'http-responses.json').write_text(json.dumps(responses,ensure_ascii=False,indent=2));return result
def commit(message):
 path.write_text(json.dumps(rows,ensure_ascii=False,indent=2)+'\n');subprocess.run(['git','-C',str(repo),'add',str(path)],check=True);subprocess.run(['git','-C',str(repo),'-c','user.name=Relevance Acceptance','-c','user.email=fixture@example.invalid','commit','-qm',message],check=True)
def project(source, records):
 snapshot={'environment':'isolated','source':source,'version':'1.2.89','tag':'v1.2.89','commit':'3dcc700174abd1b9a7d2959a09881f247026de1c','knowledge_commit':subprocess.check_output(['git','-C',str(repo),'rev-parse','HEAD'],text=True).strip(),'records':records}
 subprocess.run(['php',str(tools/'customer_service.php'),str(db),str(plugin),sid,'1.2.89'],input=json.dumps(snapshot,ensure_ascii=False),text=True,stdout=subprocess.DEVNULL,check=True)
before=receipt('cms');release=json.loads((base/'release189.json').read_text())
event={'action':'published','repository':{'full_name':'daixingwei920/daiying-cms'},'release':{k:release[k]for k in ['id','tag_name','draft','prerelease','published_at']}}
try:
 job=engine.release('reviewed-website',event);work();check(engine.db.execute('SELECT status FROM jobs WHERE id=?',(job,)).fetchone()[0]=='succeeded','Reviewed unchanged corpus acknowledged after native candidate upgrade')
 active=receipt('reviewed-website');check(len(active['record_hashes'])==7,'Seven reviewed P1 excerpts active with bound receipt')
 matrix=[]
 for key,q in questions:
  matched=query(q);matrix.append({'id':key,'question':q,'matches':matched})
  check('article.'+key in {x['knowledge_id']for x in matched} and all(x['priority']=='P1'for x in matched),'Native business retrieval: '+key)
 (base/'native-query-matrix.json').write_text(json.dumps(matrix,ensure_ascii=False,indent=2))
 check(all(x['priority']=='P0'for x in query('SQLite 安装')),'Core SQLite still uses related official P0')
 for q in ['ZXQ9384 frostwombat','是否可以支持星际飞船量子保险']:
  check(query(q)==[],'Unknown native query has no matches: '+q)
 subprocess.run(['php',str(control),'configure'],check=True,stdout=(base/'live-configured.json').open('w'))
 approval=json.loads((root/'live-provider-approved.json').read_text());check(approval['max_live_requests']==8 and time.time()<approval['expires_at'],'Explicit eight-call live provider approval valid')
 initial_events=events();router=base/'router.php';router.write_text('<?php require __DIR__."/isolation/site/public/index.php";')
 log=(base/'http-server.log').open('w');process=subprocess.Popen(['php','-S','127.0.0.1:19518','-t',str(site/'public'),str(router)],stdout=log,stderr=log)
 for _ in range(40):
  try:
   with urlopen('http://127.0.0.1:19518/health',timeout=2)as r:health=json.load(r)
   break
  except OSError:time.sleep(.1)
 check(health['version']=='1.2.89','Native HTTP site remains published Core189')
 for key,q in questions:
  answer=ask(q,True);text=answer.get('answer','');sources=answer.get('sources',[])
  check(answer.get('ok') and answer.get('grounded') and 'article.'+key in {s.get('knowledge_id')for s in sources} and all(s.get('priority')=='P1' and s.get('receipt_sha256')==active['snapshot_sha256'] for s in sources),'Live answer source and receipt: '+key)
  if key in ['82.sale','86.sale']:check(bool(re.search(r'2[,\s]?000',text)),'Expected dated USD price: '+key)
  if key=='85.install':check(all(v in text for v in ['1.2.83','8.3.33','1.0.8']),'Restaurant installation version limits retained')
  if key=='85.menu':check('草稿'in text and any(v in text for v in ['核对','审核']),'Menu draft remains subject to review')
  if key=='82.compat':check('1.2.81'in text and any(v in text for v in ['不能','不支持','不应','不在','仅','只','不允许']),'Holy City exact Core bound retained')
  if key=='86.sale':check(any(v in text for v in ['单站','每站','一个网站','1个网站']),'Restaurant is not unlimited-site licensing')
  if key=='88.sale':check('360'in text and '1.2.84'in text,'Directory price and stricter official minimum retained')
 # Deliberately synthetic authority conflict, unrelated to actual sale prices/entitlements.
 fixtures=[]
 for priority,value in [('P0','VERIFY-P0-731'),('P1','VERIFY-P1-419')]:
  content='仅用于隔离测试，与真实售价及合同无关。圣城隔离冲突验证值为'+value+'。'
  fixtures.append({'id':'conflict.'+priority,'title':'圣城隔离冲突验证','topic':'圣城','content':content,'priority':priority,'visibility':'USER-FACING','content_hash':hashlib.sha256(content.encode()).hexdigest(),'source_path':'explicit-synthetic-conflict-fixture','url':'https://fixture.invalid/conflict'})
 project('isolated-conflict-fixture',fixtures)
 conflict=ask('圣城隔离冲突验证值是什么',True)
 check(conflict.get('ok') and 'VERIFY-P0-731'in conflict['answer'] and 'VERIFY-P1-419'not in conflict['answer'] and all(s['priority']=='P0'for s in conflict['sources']),'Related P0 wins real-model conflict against related P1')
 project('isolated-conflict-fixture',[])
 for q in ['ZXQ9384 frostwombat','是否可以支持星际飞船量子保险']:
  count=events();unknown=ask(q,False);check(not unknown['grounded'] and not unknown['sources'] and events()==count,'Unknown HTTP returns insufficient information without generation')
 for r in rows:r['status']='pending'
 commit('Withdraw approved website excerpts after relevance verification');engine.tick(now=time.time()+86401);work()
 check(len(receipt('reviewed-website')['record_hashes'])==0,'Withdrawn receipt has no active website records')
 for key,q in questions:
  count=events();answer=ask(q,False);check(not answer['grounded'] and not answer['sources'] and events()==count,'Withdrawn business query has no generic-P0 or legacy fallback: '+key)
 check(receipt('cms')==before and all(x['priority']=='P0'for x in query('SQLite 安装')),'Unrelated Core official batch and P0 retrieval preserved')
 check(calls==8 and events()-initial_events==8,'Exactly eight model-success events for eight intended generation requests')
 result={'status':'PASS_ISOLATED_OWNER_REVIEW_PENDING','checks':checks,'business_queries':7,'business_passes':7,'intended_generation_requests':calls,'provider_success_event_delta':events()-initial_events,'model':approval['model'],'billing_acceptance':False,'responses':responses,'website_receipt_before_withdrawal':active,'website_receipt_after_withdrawal':receipt('reviewed-website'),'production_writes':0,'frozen190_installed':False}
 (base/'http-relevance-result.json').write_text(json.dumps(result,ensure_ascii=False,indent=2));engine.report();print(json.dumps({'status':result['status'],'checks':len(checks),'model_successes':calls}))
finally:
 if process is not None:process.terminate();process.wait(timeout=10)
 if log is not None:log.close()
 engine.db.close()
 subprocess.run(['php',str(control),'cleanup'],check=True,stdout=(base/'cleanup.log').open('w'))
