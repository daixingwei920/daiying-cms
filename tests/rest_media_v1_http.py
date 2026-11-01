"""Native REST media/discovery acceptance in a disposable site, no external services."""
import argparse
import hashlib
import http.cookiejar
import json
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--commit')
parser.add_argument('--output',type=Path)
args=parser.parse_args()
source=Path(__file__).resolve().parents[1]
commit=subprocess.check_output(['git','rev-parse',args.commit+'^{commit}'],cwd=source,text=True).strip() if args.commit else None
checks=[]
def check(ok,label):
    if not ok: raise AssertionError(label)
    checks.append(label);print('PASS '+label,flush=True)
with tempfile.TemporaryDirectory(prefix='cms-rest-media-') as temp:
    site=Path(temp)
    if commit:
        with tempfile.TemporaryFile() as archive:
            subprocess.run(['git','archive',commit],cwd=source,stdout=archive,check=True);archive.seek(0)
            subprocess.run(['tar','-xf','-','-C',str(site)],stdin=archive,check=True)
    else:
        for name in filter(None,subprocess.check_output(['git','ls-files','--cached','--others','--exclude-standard','-z'],cwd=source).decode().split('\0')):
            origin=source/name
            if origin.is_file():
                target=site/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(origin,target)
    (site/'sessions').mkdir(mode=0o700)
    init=r'''
$r=$argv[1];require $r.'/system/core/Bootstrap/autoload.php';$c=require $r.'/config/app.example.php';
$c['app']['secure_cookies']=false;$c['site']['url']='http://127.0.0.1';$c['database']=['dsn'=>'sqlite:'.$r.'/storage/test.sqlite'];
$c['market']['enabled']=false;$c['security']['encryption_key']=bin2hex(random_bytes(32));$c['api']['admin_token_sha256']=hash('sha256','isolated-rest-token');$c['media']['max_file_bytes']=1024;
file_put_contents($r.'/config/app.php',"<?php\nreturn ".var_export($c,true).";\n");chmod($r.'/config/app.php',0600);
$s=Cms\Core\Config\Settings::fromArray($c);$p=Cms\Core\Database\ConnectionFactory::make($s);$f=glob($r.'/system/migrations/*.php');sort($f);
(new Cms\Core\Migration\MigrationRunner($p,array_map(static fn($x)=>require $x,$f)))->run();
$a=new Cms\Core\Auth\AdminAuthenticator($p);$a->createAdmin('owner@example.invalid','Isolated-only!','Owner');$a->createAdmin('restricted@example.invalid','Isolated-only!','Restricted');
file_put_contents($r.'/storage/installed.lock','ISOLATED REST MEDIA');
'''
    subprocess.run(['php','-r',init,str(site)],check=True)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}'
    anon=urllib.request.build_opener()
    admin=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    restricted=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(path,method='GET',data=None,headers=None,client=anon):
        req=urllib.request.Request(base+path,data=data,headers=headers or {},method=method)
        try:
            with client.open(req,timeout=20) as response:return response.status,response.read().decode()
        except urllib.error.HTTPError as error:return error.code,error.read().decode()
    def json_request(path,method='GET',data=None,headers=None,client=anon):
        code,body=request(path,method,json.dumps(data).encode() if data is not None else None,{'Content-Type':'application/json',**(headers or {})},client)
        return code,json.loads(body)
    def upload(name,content,headers=None,client=anon,csrf=None):
        boundary='cms-isolated-media-boundary'
        body=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+content+b'\r\n'
        if csrf is not None:body+=(f'--{boundary}\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n{csrf}\r\n').encode()
        body+=(f'--{boundary}--\r\n').encode()
        code,text=request('/api/v1/media','POST',body,{'Content-Type':f'multipart/form-data; boundary={boundary}',**(headers or {})},client)
        return code,json.loads(text)
    def rows():
        with sqlite3.connect(site/'storage/test.sqlite') as db:return db.execute('SELECT COUNT(*) FROM cms_media').fetchone()[0]
    server=None
    try:
        with (site/'http.log').open('w') as log:
            server=subprocess.Popen(['php','-d','session.save_path='+str(site/'sessions'),'-S',f'127.0.0.1:{port}','-t',str(site/'public'),str(site/'public/index.php')],stdout=log,stderr=log)
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.1)
            code,index=json_request('/api/v1');check(code==200 and index['data']['resources']['content_types']=='/api/v1/content-types','index advertises content-type discovery')
            check(index['data']['resource_capabilities']['media']['upload_field']=='file' and 'PATCH' in index['data']['resource_capabilities']['media']['methods'],'discovery describes actual media methods and auth contract')
            code,types=json_request('/api/v1/content-types');check(code==200 and {t['id'] for t in types['data']['items']}=={'article','page'},'only actual REST-exposed Core content types are discovered')
            check('isolated-rest-token' not in json.dumps(index) and 'admin_token_sha256' not in json.dumps(types),'discovery contains no credentials or configuration')
            code,_=upload('unauth.txt',b'no access');check(code==401 and rows()==0,'anonymous uploads cannot create media')
            token={'Authorization':'Bearer isolated-rest-token'}
            code,bad=json_request('/api/v1/media','POST',{'path':'/etc/passwd','url':'http://127.0.0.1/private'},token);check(code==422 and rows()==0,'JSON local-path and URL uploads are refused')
            ids=[]
            for i in range(4):
                code,item=upload(f'fixture-{i}.txt',f'isolated media text {i}'.encode(),token)
                check(code==201 and item['data']['item']['id']>0,'authenticated multipart upload '+str(i)+' succeeds')
                ids.append(item['data']['item']['id'])
            check(rows()==4,'uploads persist through the native media library')
            code,page1=json_request('/api/v1/media?per_page=2&page=1');code2,page2=json_request('/api/v1/media?per_page=2&page=2')
            a={x['id'] for x in page1['data']['items']};b={x['id'] for x in page2['data']['items']}
            check(code==code2==200 and len(a)==len(b)==2 and not a&b and a|b==set(ids),'page two returns real disjoint media instead of an empty placeholder')
            check(page1['data']['pagination']['has_more'] and not page2['data']['pagination']['has_more'],'has_more uses a real lookahead and is false at the final full page')
            code,invalid=json_request('/api/v1/media/not-an-id');check(code==404,'invalid single media ids do not fall back to the collection')
            code,item=json_request('/api/v1/media/'+str(ids[0]));check(code==200 and item['data']['item']['filename']=='fixture-0.txt','single media read returns a public view model')
            check(str(site) not in json.dumps(item) and 'storage_key' not in json.dumps(item),'single media does not disclose server storage paths')
            code,patched=json_request('/api/v1/media/'+str(ids[0]),'PATCH',{'description':'Keep description','alt_text':'Keep alt','title':'New title'},token);check(code==200 and patched['data']['item']['description']=='Keep description','metadata PATCH works with bearer auth')
            code,patched=json_request('/api/v1/media/'+str(ids[0]),'PATCH',{'title':'Title only'},token);check(code==200 and patched['data']['item']['alt_text']=='Keep alt' and patched['data']['item']['description']=='Keep description','partial metadata PATCH preserves omitted fields')
            code,_=json_request('/api/v1/media/'+str(ids[0]),'PATCH',{'storage_key':'../overwrite'},token);check(code==422,'metadata PATCH rejects storage and unknown fields')
            code,_=json_request('/api/v1/media/'+str(ids[0]),'PATCH',{'title':'forbidden'});check(code==401,'anonymous metadata writes are refused')
            before=rows()
            for name,content in [('bad.php',b'<?php echo 1;'),('bad.svg',b'<svg></svg>'),('fake.png',b'not an image'),('large.txt',b'x'*1025)]:
                code,item=upload(name,content,token);check(code==422 and rows()==before and str(site) not in json.dumps(item),'native safety rejects '+name+' without a media row or path leak')
            for client,email in [(admin,'owner@example.invalid'),(restricted,'restricted@example.invalid')]:
                _,login=request('/admin/login',client=client);csrf=re.search(r'name="_csrf" value="([^"]+)"',login)[1]
                request('/admin/login','POST',urllib.parse.urlencode({'email':email,'password':'Isolated-only!','_csrf':csrf}).encode(),{'Content-Type':'application/x-www-form-urlencoded'},client)
            code,_=upload('session.txt',b'session media',client=admin);check(code==403,'cookie session uploads require CSRF')
            _,page=request('/admin',client=admin);csrf=re.search(r'name="_csrf" value="([^"]+)"',page)[1]
            code,_=upload('session.txt',b'session media',client=admin,csrf=csrf);check(code==201,'native media administrator session with CSRF can upload')
            _,page=request('/admin',client=restricted);limited_csrf=re.search(r'name="_csrf" value="([^"]+)"',page)[1]
            code,_=upload('restricted.txt',b'restricted media',client=restricted,csrf=limited_csrf);check(code==403,'restricted administrator cannot write without media.manage')
            with sqlite3.connect(site/'storage/test.sqlite') as db:db.execute("UPDATE cms_admin_sessions SET revoked_at='fixture-revoked' WHERE admin_id=1")
            code,_=upload('revoked.txt',b'revoked media',client=admin,csrf=csrf);check(code==401,'revoked administrator sessions cannot upload')
            with sqlite3.connect(site/'storage/test.sqlite') as db:
                db.execute("UPDATE cms_media SET status='Deleted' WHERE id=?",[ids[0]])
                audits=db.execute("SELECT COUNT(*) FROM cms_audit_logs WHERE action IN ('media.uploaded','media.metadata_updated')").fetchone()[0]
            code,_=json_request('/api/v1/media/'+str(ids[0]));check(code==404,'deleted media is hidden from single reads')
            check(audits>=7,'successful uploads and metadata changes have native audit records')
            code,_=json_request('/api/v1/media?type=invalid');check(code==422,'media type filters use a finite allowlist')
            code,empty=json_request('/api/v1/media?page=100001&per_page=500');check(code==200 and empty['data']['pagination']['page']==100000 and empty['data']['pagination']['per_page']==50,'pagination inputs remain bounded')
            if args.output:
                args.output.parent.mkdir(parents=True,exist_ok=True)
                args.output.write_text(json.dumps({'status':'PASS','source_commit':commit,'assertion_count':len(checks),'checks':checks,'external_calls':0,'signing_keys_generated':False},ensure_ascii=False,indent=2)+'\n')
    finally:
        if server:server.terminate();server.wait(timeout=10)
