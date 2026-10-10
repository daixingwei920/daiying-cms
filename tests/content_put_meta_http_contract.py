#!/usr/bin/env python3
"""Real REST route regression, disposable copied instance; no production access."""
import argparse, pathlib, subprocess, shutil, tempfile, json, urllib.request, urllib.error, socket, time, hashlib
p=argparse.ArgumentParser();p.add_argument('source');p.add_argument('--mode',choices=['baseline','regression','fixed'],default='fixed');p.add_argument('--mysql',action='store_true');p.add_argument('--output',required=True);args=p.parse_args()
src=pathlib.Path(args.source).resolve();root=pathlib.Path(tempfile.mkdtemp(prefix='cms-put-http-'));checks=[]
shutil.copytree(src,root,dirs_exist_ok=True,ignore=shutil.ignore_patterns('.git','storage','outputs','__pycache__'))
(root/'storage/database').mkdir(parents=True);(root/'storage/logs').mkdir();(root/'content/uploads').mkdir(exist_ok=True)
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();base='http://127.0.0.1:'+str(port)
database='cms_mysql_put_'+root.name.rsplit('-',1)[-1].replace('-','')
if args.mysql:
 assert args.mode=='fixed' and database.replace('_','').isalnum()
 subprocess.run(['sudo','mysql','-e',f"CREATE DATABASE {database} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON {database}.* TO 'cms_rc1'@'localhost';"],check=True)
setup=r'''$root=$argv[1];define('CMS_ROOT',$root);require $root.'/system/core/Bootstrap/autoload.php';$c=require $root.'/config/app.example.php';$c['database']=['dsn'=>$argv[3]==='mysql'?'mysql:host=127.0.0.1;dbname='.$argv[4].';charset=utf8mb4':'sqlite:'.$root.'/storage/database/cms.sqlite','username'=>$argv[3]==='mysql'?'cms_rc1':'','password'=>$argv[3]==='mysql'?'local-isolation-only':'','options'=>[]];$c['app']['secure_cookies']=false;$c['site']['id']='synthetic-put-fixture';$c['site']['url']=$argv[2];$c['updates']['server_url']='https://updates.invalid';$c['api']['admin_token_sha256']=hash('sha256','synthetic-put-http-only');file_put_contents($root.'/config/app.php','<?php return '.var_export($c,true).';');$pdo=new PDO($c['database']['dsn'],$c['database']['username'],$c['database']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$m=[];foreach(glob($root.'/system/migrations/*.php')as$f)$m[]=require$f;(new Cms\Core\Migration\MigrationRunner($pdo,$m))->run();file_put_contents($root.'/storage/installed.lock','synthetic');'''
subprocess.run(['php','-r',setup,str(root),base,'mysql' if args.mysql else 'sqlite',database],check=True)
cli=r'''$root=$argv[1];define('CMS_ROOT',$root);require $root.'/system/core/Bootstrap/autoload.php';$c=require $root.'/config/app.php';$p=new PDO($c['database']['dsn'],$c['database']['username'],$c['database']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$r=new Cms\Core\Content\ContentRepository($p,Cms\Core\Content\ContentTypeRegistry::defaults(),[],$root);$id=(int)$argv[2];if(($argv[3]??'')==='opaque'){$v=$r->find($id)['meta'];$v['extension_state']=['owner'=>'keep'];$p->prepare('UPDATE cms_contents SET meta_json=? WHERE id=?')->execute([json_encode($v),$id]);}echo json_encode(['item'=>$r->find($id),'terms'=>$r->termsForContent($id)],JSON_THROW_ON_ERROR);'''
def state(id,opaque=False):return json.loads(subprocess.check_output(['php','-r',cli,str(root),str(id),'opaque' if opaque else 'read']))
def check(label,ok):
 checks.append({'name':label,'status':'PASS' if ok else 'FAIL'});assert ok,label
op=urllib.request.build_opener(urllib.request.ProxyHandler({}))
def http(method,path,body=None,auth=True,headers=None):
 h={'Content-Type':'application/json'}
 if auth:h['Authorization']='Bearer synthetic-put-http-only'
 h.update(headers or {});req=urllib.request.Request(base+path,data=None if body is None else json.dumps(body).encode(),headers=h,method=method)
 try:r=op.open(req,timeout=10)
 except urllib.error.HTTPError as e:r=e
 raw=r.read().decode();return r.status,dict(r.headers),json.loads(raw) if r.headers.get('Content-Type','').startswith('application/json') else raw
# PUT is implemented by the controller but is NOT registered in Application.
# Never add a fixture route or pretend a direct call is HTTP acceptance.
def put_handler(path,body,auth=True,headers=None):
 php=r"$root=$argv[1];define('CMS_ROOT',$root);require $root.'/system/core/Bootstrap/autoload.php';$c=require $root.'/config/app.php';$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$api=new Cms\Core\Rest\ApiV1Controller(Cms\Core\Config\Settings::fromArray($c),$root);$r=$api->contents(new Cms\Core\Http\Request('PUT',$in['path'],[],$in['body'],$in['server']));echo json_encode(['status'=>$r->status(),'body'=>json_decode($r->body(),true)]);"
 server={'HTTP_AUTHORIZATION':'Bearer synthetic-put-http-only'} if auth else {}
 for k,v in (headers or {}).items():server['HTTP_'+k.upper().replace('-','_')]=v
 v=json.loads(subprocess.check_output(['php','-r',php,str(root)],input=json.dumps({'path':path,'body':body,'server':server}).encode()))
 return v['status'],{},v['body']
def write(method,path,body=None,auth=True,headers=None):
 return put_handler(path,body,auth,headers) if method=='PUT' else http(method,path,body,auth,headers)
meta={'seo_title':'独立 SEO Title','seo_description':'保留描述','seo_keywords':'戴影, 保留','target_keywords':'其他目标字段','canonical_url':base+'/articles/put-meta','paid_content_enabled':True,'paid_content_price_minor':1234,'paid_content_currency':'USD','preview_token':'synthetic-preview-only','preview_expires_at':'2030-01-01T00:00:00+00:00'}
seq=0
def article():
 global seq;seq+=1
 status,_,j=http('POST','/api/v1/contents',{'title':'Original','slug':'put-meta-'+str(seq),'blocks':[{'type':'paragraph','data':{'text':'真实 REST 测试正文'}}],'status':'published','meta':meta,'categories':['News'],'tags':['CMS']})
 check('authenticated POST '+str(seq),status==201);return j['data']['item']['id']
log=open(root/'storage/logs/http.log','w');server=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(root/'public'),str(root/'public/index.php')],stdout=log,stderr=log)
result={'mode':args.mode,'database':'mysql' if args.mysql else 'sqlite','php':subprocess.check_output(['php','-r','echo PHP_VERSION;'],text=True),'checks':checks}
try:
 for _ in range(40):
  try:
   if http('GET','/health')[0]==200:break
  except (OSError,ValueError):time.sleep(.1)
 id=article();before=state(id);route_status,_,_=http('PUT',f'/api/v1/contents/{id}',{'title':'修改后的标题'});check('real HTTP PUT route absent 405 without mutation',route_status==405 and state(id)==before);result['real_http_put_status']=route_status;status,_,j=write('PUT',f'/api/v1/contents/{id}',{'title':'修改后的标题'});after=state(id)
 result['put_controller_status']=status;check('direct PUT controller title 200',status==200)
 changed=[k for k in before['item']['meta'] if before['item']['meta'][k]!=after['item']['meta'].get(k)]
 result['omitted_meta']={'changed_fields':changed,'before':before['item']['meta'].copy(),'after':after['item']['meta'].copy(),'terms_before':before['terms'],'terms_after':after['terms']}
 for v in [result['omitted_meta']['before'],result['omitted_meta']['after']]:v['preview_token_sha256']=hashlib.sha256(v.pop('preview_token').encode()).hexdigest()
 check('PUT missing taxonomy retains legacy clear',after['terms']==[])
 check('stable preserved / candidate regression / fixed preserved',bool(changed)==(args.mode=='regression'))
 if args.mode in ['baseline','regression']:
  for payload,label in [({'meta':[]},'empty'),({'meta':None},'null')]:
   aid=article();old=state(aid);status,_,_=write('PUT',f'/api/v1/contents/{aid}',payload);new=state(aid)
   result[label]={'direct_controller_status':status,'meta_unchanged':old['item']['meta']==new['item']['meta'],'paid_enabled':new['item']['meta']['paid_content_enabled']}
 else:
  aid=article();old=state(aid,True)
  status,_,_=write('PUT',f'/api/v1/contents/{aid}',{'title':'A with opaque'});check('A omitted meta preserves all including opaque',status==200 and state(aid)['item']['meta']==old['item']['meta'])
  status,_,_=write('PUT',f'/api/v1/contents/{aid}',{'meta':{'seo_title':'Replacement'}});v=state(aid)['item']['meta'];check('B explicit meta fully replaces',status==200 and v['seo_title']=='Replacement' and not v['paid_content_enabled'] and 'extension_state' not in v)
  aid=article();old=state(aid);status,_,_=write('PUT',f'/api/v1/contents/{aid}',{'meta':[]});v=state(aid)['item']['meta'];check('C explicit empty resets known defaults',status==200 and not v['paid_content_enabled'] and v['paid_content_price_minor']==0 and v['preview_token']!=old['item']['meta']['preview_token'])
  aid=article();old=state(aid);status,_,_=write('PUT',f'/api/v1/contents/{aid}',{'meta':None});check('D explicit null is 422 unchanged',status==422 and state(aid)==old)
  aid=article();old=state(aid,True);status,_,_=http('PATCH',f'/api/v1/contents/{aid}',{'title':'PATCH title'});v=state(aid);check('E PATCH title preserves meta and taxonomy',status==200 and v['item']['meta']==old['item']['meta'] and v['terms']==old['terms'])
  status,_,_=http('PATCH',f'/api/v1/contents/{aid}',{'meta':{'seo_title':'Single SEO'}});expected=old['item']['meta'].copy();expected['seo_title']='Single SEO';check('F single SEO preserves paid preview opaque',status==200 and state(aid)['item']['meta']==expected)
  status,_,_=http('PATCH',f'/api/v1/contents/{aid}',{'meta_mode':'replace','meta':{'seo_title':'Explicit replace'}});check('G PATCH explicit replace',status==200 and not state(aid)['item']['meta']['paid_content_enabled'])
  aid=article();status,headers,j=http('GET',f'/api/v1/contents/{aid}');etag=headers.get('ETag');check('real GET revision ETag',status==200 and bool(etag))
  http('PATCH',f'/api/v1/contents/{aid}',{'title':'Owner edit','meta':{'seo_description':'Owner description'}});owner=state(aid)
  status,_,_=http('PATCH',f'/api/v1/contents/{aid}',{'title':'HTTP overwrite'},headers={'If-Match':etag});check('real HTTP PATCH stale If-Match 409 no change',status==409 and state(aid)==owner)
  status,_,_=write('PUT',f'/api/v1/contents/{aid}',{'title':'overwrite'},headers={'If-Match':etag});check('I stale If-Match 409 no change',status==409 and state(aid)==owner)
  status,_,_=write('PUT',f'/api/v1/contents/{aid}',{'title':'legacy without condition'});check('J old client without If-Match compatible',status==200 and state(aid)['item']['meta']==owner['item']['meta'])
  status,_,_=write('PUT',f'/api/v1/contents/{aid}',{'title':'unauthorized'},auth=False);check('401 unauthorized no change',status==401 and state(aid)['item']['title']=='legacy without condition')
  status,_,_=http('PATCH',f'/api/v1/contents/{aid}',{'title':'HTTP unauthorized'},auth=False);check('real HTTP PATCH 401 no change',status==401 and state(aid)['item']['title']=='legacy without condition')
  status,_,read=http('GET',f'/api/v1/contents/{aid}');check('real HTTP GET meta reflects paid SEO preview expiry',status==200 and read['data']['item']['meta']['paid_content_price_minor']==1234 and read['data']['item']['meta']['seo_description']=='Owner description' and read['data']['item']['meta']['preview_expires_at']==meta['preview_expires_at'])
  old=state(aid);status,_,_=http('PATCH',f'/api/v1/contents/{aid}',{'meta':None});check('422 PATCH null no change',status==422 and state(aid)==old)
  status,_,_=write('PUT',f'/api/v1/contents/{aid}',{'categories':['Replacement category'],'tags':['Replacement tag']});check('explicit PUT taxonomy replacement',status==200 and {t['name'] for t in state(aid)['terms']}=={'Replacement category','Replacement tag'})
  aid=article();status,_,_=http('PATCH',f'/api/v1/contents/{aid}',{'meta':{'paid_content_enabled':False}});status,_,html=http('GET','/articles/put-meta-'+str(seq));check('public HTML body SEO description keywords canonical',status==200 and all(s in html for s in ['真实 REST 测试正文','独立 SEO Title','保留描述','戴影, 保留',base+'/articles/put-meta']))
 result['status']='PASS'
except Exception as error:
 result['status']='FAIL';result['error']=str(error);raise
finally:
 server.terminate();server.wait();log.close();pathlib.Path(args.output).write_text(json.dumps(result,ensure_ascii=False,indent=2));print(json.dumps({'status':result.get('status'),'checks':len(checks),'root':str(root),'database':result['database'],'error':result.get('error')},ensure_ascii=False))
