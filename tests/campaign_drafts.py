#!/usr/bin/env python3
"""Small real-HTTP campaign workflow check with synthetic DB and identities."""
import http.client
from http.cookies import SimpleCookie
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlencode

ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BINARY','php')
DB='''<?php
class CampaignResult {
    private $rows;
    function __construct($rows) {$this->rows=$rows;}
    function fetch_assoc() {return array_shift($this->rows);}
}
class CampaignStatement {
    private $sql; private $params=[]; private $rows=[];
    public $insert_id=0; public $affected_rows=0;
    function __construct($sql) {$this->sql=$sql;}
    function bind_param($types,&...$values) {$this->params=&$values;}
    function execute() {
        $file=getenv('CAMPAIGN_FIXTURE_DATA');
        $data=json_decode(file_get_contents($file),true); $p=$this->params;
        if (str_contains($this->sql,'FROM advertiser_users')) {
            $this->rows=($p[0]===7 && $p[1]===9) || ($p[0]===8 && $p[1]===10) ? [['id'=>$p[0]]] : [];
        } elseif (str_starts_with($this->sql,'SELECT id, name')) {
            if (!str_contains($this->sql,'advertiser_id = ?')) throw new RuntimeException('Unscoped read');
            $r=$data[(string)$p[0]] ?? null;
            $this->rows=$r && $r['advertiser_id']===$p[1] ? [$r] : [];
        } elseif (str_starts_with($this->sql,'INSERT INTO ad_campaigns')) {
            $this->insert_id=max(array_map('intval',array_keys($data)))+1;
            $data[(string)$this->insert_id]=['id'=>$this->insert_id,'advertiser_id'=>$p[0],
                'name'=>$p[1],'status'=>'draft','target_radius_km'=>$p[2],'media_type'=>$p[3]];
            file_put_contents($file,json_encode($data));
        } elseif (str_starts_with($this->sql,'UPDATE ad_campaigns')) {
            if (!str_contains($this->sql,"advertiser_id = ? AND status = 'draft'")) throw new RuntimeException('Unscoped update');
            $r=$data[(string)$p[3]] ?? null;
            if ($r && $r['advertiser_id']===$p[4] && $r['status']==='draft'
                && $r['name']===$p[5] && $r['target_radius_km']===$p[6] && $r['media_type']===$p[7]) {
                $next=array_replace($r,['name'=>$p[0],'target_radius_km'=>$p[1],'media_type'=>$p[2]]);
                $this->affected_rows=$next===$r ? 0 : 1; $data[(string)$p[3]]=$next;
                file_put_contents($file,json_encode($data));
            }
        } else throw new RuntimeException('Unexpected fixture SQL');
        return true;
    }
    function get_result() {return new CampaignResult($this->rows);}
    function close() {}
}
class CampaignConnection {function prepare($sql) {return new CampaignStatement(trim($sql));}}
$conn=new CampaignConnection();
'''
ROUTER='''<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path!=='/campaign_edit.php') return false;
session_start();
if(isset($_GET['_as'])) {
    $_SESSION=[];
    if($_GET['_as']==='owner') $_SESSION=['adv_user_id'=>7,'adv_advertiser_id'=>9,'adv_advertiser_name'=>'Synthetic Gym'];
    if($_GET['_as']==='staff') $_SESSION=['adv_user_id'=>11,'adv_advertiser_id'=>9];
    if($_GET['_as']==='foreign') $_SESSION=['adv_user_id'=>8,'adv_advertiser_id'=>10];
}
session_write_close(); require __DIR__.'/campaign_edit.php';
'''

def main():
    with tempfile.TemporaryDirectory(prefix='activbuds-campaigns-') as folder:
        root=Path(folder)
        for name in ('campaign_edit.php','includes/campaigns.php','includes/header.php',
                     'includes/footer.php','assets/campaigns.css','assets/campaigns.js'):
            dest=root/name; dest.parent.mkdir(parents=True,exist_ok=True)
            shutil.copyfile(ROOT/name,dest)
        (root/'includes/db.php').write_text(DB)
        (root/'router.php').write_text(ROUTER)
        (root/'sessions').mkdir()
        data=root/'data.json'
        data.write_text(json.dumps({'1':{'id':1,'advertiser_id':9,'name':'Active campaign',
            'status':'active','target_radius_km':10,'media_type':'image'}}))
        with socket.socket() as s:
            s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
        with (root/'server.log').open('w+') as log:
            proc=subprocess.Popen([PHP,'-d','output_buffering=0','-d','display_errors=1',
                '-d','session.save_path='+str(root/'sessions'),'-S',f'127.0.0.1:{port}',
                '-t',str(root),str(root/'router.php')],env={**os.environ,'CAMPAIGN_FIXTURE_DATA':str(data)},
                stdout=log,stderr=log)
            try:
                for _ in range(100):
                    if proc.poll() is not None: raise RuntimeError('PHP server stopped')
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.1): break
                    except OSError: time.sleep(.02)
                cookies={}; checks=0
                def req(path,fields=None,client='owner'):
                    nonlocal checks
                    conn=http.client.HTTPConnection('127.0.0.1',port,timeout=5)
                    headers={'Content-Type':'application/x-www-form-urlencoded'}
                    if client in cookies: headers['Cookie']=cookies[client]
                    try:
                        conn.request('POST' if fields is not None else 'GET',path,
                            urlencode(fields) if fields is not None else None,headers)
                        response=conn.getresponse(); body=response.read().decode()
                        cookie=SimpleCookie(); cookie.load(response.getheader('Set-Cookie',''))
                        if 'PHPSESSID' in cookie: cookies[client]='PHPSESSID='+cookie['PHPSESSID'].value
                        assert '<b>Warning</b>' not in body and '<b>Fatal error</b>' not in body,body
                        checks+=1
                        return response.status,response.getheader('Location'),body
                    finally: conn.close()
                def token(html,name):
                    return re.search('name="'+name+'" value="([a-f0-9]+)"',html).group(1)
                status,location,_=req('/campaign_edit.php',client='out')
                assert status==302 and location=='advertiser_login.php'
                status,_,html=req('/campaign_edit.php?_as=owner')
                assert status==200 and 'Create a campaign' in html
                csrf=token(html,'csrf_token')
                valid={'name':'Summer <training>','target_radius_km':'25','media_type':'carousel','csrf_token':csrf}
                assert req('/campaign_edit.php',{**valid,'csrf_token':'wrong'})[0]==403
                assert req('/campaign_edit.php',{**valid,'target_radius_km':'0'})[0]==422
                assert len(json.loads(data.read_text()))==1
                status,location,_=req('/campaign_edit.php',valid)
                assert status==303 and location=='campaign_edit.php?id=2'
                status,_,html=req('/'+location)
                assert status==200 and 'Draft saved.' in html and 'Summer &lt;training&gt;' in html
                revision=token(html,'revision')
                assert req('/campaign_edit.php?id=2',{**valid,'revision':revision,'name':'Updated campaign'})[0]==303
                assert json.loads(data.read_text())['2']['name']=='Updated campaign'
                assert req('/campaign_edit.php?id=2',{**valid,'revision':revision})[0]==409
                status,_,html=req('/campaign_edit.php?id=2')
                current=token(html,'revision')
                assert req('/campaign_edit.php?id=2',{**valid,'name':'Updated campaign','revision':current})[0]==303
                assert req('/campaign_edit.php?_as=staff',client='staff')[0]==403
                assert req('/campaign_edit.php?_as=foreign&id=2',client='foreign')[0]==404
                status,_,html=req('/campaign_edit.php?id=1')
                assert status==200 and 'fieldset disabled' in html and 'Save draft' not in html
                assert req('/campaign_edit.php?id=1',{**valid,'revision':token(html,'revision')})[0]==409
                assert req('/campaign_edit.php?id[]=2')[0]==404
                assert len(json.loads(data.read_text()))==2
                print(f'PASS {checks} actual HTTP requests: create/reopen/edit/no-change, CSRF, '
                      'validation, stale draft, active refusal and advertiser isolation; synthetic DB only',flush=True)
                if '--preview' in sys.argv:
                    print(f'PREVIEW http://127.0.0.1:{port}/campaign_edit.php?_as=owner',flush=True)
                    while True: time.sleep(1)
            finally:
                proc.terminate()
                try: proc.wait(timeout=3)
                except subprocess.TimeoutExpired: proc.kill(); proc.wait()

if __name__=='__main__': main()
