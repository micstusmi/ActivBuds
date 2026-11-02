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
    function bind_param($types,&...$values) {if(strlen($types)!==count($values)) throw new RuntimeException('Bind signature mismatch'); $this->params=&$values;}
    function execute() {
        $file=getenv('CAMPAIGN_FIXTURE_DATA');
        $data=json_decode(file_get_contents($file),true); $p=$this->params;
        if (str_starts_with($this->sql,'SELECT id, name, city')) {
            $all=[['id'=>10,'advertiser_id'=>9,'name'=>'Main Studio','city'=>'Oakleigh','state'=>'VIC','country'=>'Australia'],
                  ['id'=>11,'advertiser_id'=>9,'name'=>'Outdoor Club','city'=>'Pakenham','state'=>'VIC','country'=>'Australia'],
                  ['id'=>99,'advertiser_id'=>10,'name'=>'Other advertiser venue','city'=>'Synthetic','state'=>'VIC','country'=>'Australia']];
            $this->rows=array_values(array_filter($all,fn($r)=>$r['advertiser_id']===$p[0]));
        } elseif (str_starts_with($this->sql,'SELECT role FROM advertiser_users')) {
            $valid=($p[0]===7 && $p[1]===9) || ($p[0]===8 && $p[1]===10) || ($p[0]===11 && $p[1]===9);
            $this->rows=$valid ? [['role'=>$p[0]===11 ? 'staff' : 'owner']] : [];
        } elseif (str_contains($this->sql,'SUM(impressions)')) {
            if (($_GET['_metrics'] ?? '')==='missing') throw new RuntimeException('Synthetic missing metrics');
            $this->rows=[['impressions'=>100,'clicks'=>5,'spend'=>1.25]];
        } elseif (str_contains($this->sql,'FROM ad_campaigns WHERE advertiser_id')) {
            $literal=substr($p[1],1,-1);
            $literal=preg_replace('/=(.)/s','$1',$literal);
            $rows=array_values(array_filter($data,function($r) use($p,$literal) {
                return $r['advertiser_id']===$p[0] && ($p[2]==='' || $r['status']===$p[2])
                    && stripos($r['name'],$literal)!==false;
            }));
            usort($rows,fn($a,$b)=>$b['id']<=>$a['id']); $this->rows=array_slice($rows,0,100);
        } elseif (str_contains($this->sql,'FROM advertiser_users')) {
            $this->rows=($p[0]===7 && $p[1]===9) || ($p[0]===8 && $p[1]===10) ? [['id'=>$p[0]]] : [];
        } elseif (str_starts_with($this->sql,'SELECT id, name')) {
            if (!str_contains($this->sql,'advertiser_id = ?')) throw new RuntimeException('Unscoped read');
            $r=$data[(string)$p[0]] ?? null;
            $this->rows=$r && $r['advertiser_id']===$p[1] ? [$r] : [];
        } elseif (str_starts_with($this->sql,'INSERT INTO ad_campaigns')) {
            if($p[7]!==null && !(($p[10]===9 && in_array($p[9],[10,11],true)) || ($p[10]===10 && $p[9]===99))) return true;
            $this->insert_id=max(array_map('intval',array_keys($data)))+1;
            $data[(string)$this->insert_id]=['id'=>$this->insert_id,'advertiser_id'=>$p[0],
                'name'=>$p[1],'status'=>'draft','target_radius_km'=>$p[2],'media_type'=>$p[3],
                'headline'=>$p[4],'message'=>$p[5],'destination_url'=>$p[6],'location_id'=>$p[7]];
            file_put_contents($file,json_encode($data));
        } elseif (str_starts_with($this->sql,'UPDATE ad_campaigns SET status')) {
            $r=$data[(string)$p[1]] ?? null;
            if ($r && $r['advertiser_id']===$p[2] && $r['status']===$p[3]) {
                $data[(string)$p[1]]['status']=$p[0]; $this->affected_rows=1;
                file_put_contents($file,json_encode($data));
            }
        } elseif (str_starts_with($this->sql,'UPDATE ad_campaigns')) {
            if (!str_contains($this->sql,"advertiser_id = ? AND status = 'draft'")) throw new RuntimeException('Unscoped update');
            $r=$data[(string)$p[7]] ?? null;
            if ($r && $r['advertiser_id']===$p[8] && $r['status']==='draft'
                && $r['name']===$p[9] && $r['target_radius_km']===$p[10] && $r['media_type']===$p[11]
                && $r['headline']===$p[12] && $r['message']===$p[13] && $r['destination_url']===$p[14]
                && $r['location_id']===$p[15]
                && ($p[16]===null || ($p[18]===9 && in_array($p[17],[10,11],true)) || ($p[18]===10 && $p[17]===99))) {
                $next=array_replace($r,['name'=>$p[0],'target_radius_km'=>$p[1],'media_type'=>$p[2],
                    'headline'=>$p[3],'message'=>$p[4],'destination_url'=>$p[5],'location_id'=>$p[6]]);
                $this->affected_rows=$next===$r ? 0 : 1; $data[(string)$p[7]]=$next;
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
if(!in_array($path,['/campaign_edit.php','/advertiser_dashboard.php'],true)) return false;
session_start();
if(isset($_GET['_as'])) {
    $_SESSION=[];
    if($_GET['_as']==='owner') $_SESSION=['adv_user_id'=>7,'adv_advertiser_id'=>9,'adv_advertiser_name'=>'Synthetic Gym'];
    if($_GET['_as']==='staff') $_SESSION=['adv_user_id'=>11,'adv_advertiser_id'=>9];
    if($_GET['_as']==='foreign') $_SESSION=['adv_user_id'=>8,'adv_advertiser_id'=>10];
}
session_write_close(); require __DIR__.$path;
'''

def main():
    with tempfile.TemporaryDirectory(prefix='activbuds-campaigns-') as folder:
        root=Path(folder)
        for name in ('campaign_edit.php','advertiser_dashboard.php','includes/campaigns.php','includes/header.php',
                     'includes/footer.php','assets/campaigns.css','assets/campaigns.js'):
            dest=root/name; dest.parent.mkdir(parents=True,exist_ok=True)
            shutil.copyfile(ROOT/name,dest)
        (root/'includes/db.php').write_text(DB)
        (root/'router.php').write_text(ROUTER)
        (root/'sessions').mkdir()
        data=root/'data.json'
        data.write_text(json.dumps({'1':{'id':1,'advertiser_id':9,'name':'Active campaign',
            'status':'active','target_radius_km':10,'media_type':'image',
            'headline':'','message':'','destination_url':'','location_id':None}}))
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
                valid={'name':'Summer <training>','target_radius_km':'25','media_type':'carousel','csrf_token':csrf,'create_key':token(html,'create_key'),
                    'headline':'Try <outdoor> training','message':'Meet your next training partner.',
                    'destination_url':'https://example.test/training','location_id':'10'}
                assert req('/campaign_edit.php',{**valid,'csrf_token':'wrong'})[0]==403
                assert req('/campaign_edit.php',{**valid,'target_radius_km':'0'})[0]==422
                assert req('/campaign_edit.php',{**valid,'destination_url':'javascript:alert(1)'})[0]==422
                assert req('/campaign_edit.php',{**valid,'destination_url':'https://user:pass@example.test/'})[0]==422
                assert req('/campaign_edit.php',{**valid,'headline':'x'*91})[0]==422
                assert req('/campaign_edit.php',{**valid,'location_id':'99'})[0]==422
                assert len(json.loads(data.read_text()))==1
                status,location,_=req('/campaign_edit.php',valid)
                assert status==303 and location=='campaign_edit.php?id=2'
                status,_,html=req('/'+location)
                assert status==200 and 'Draft saved.' in html and 'Summer &lt;training&gt;' in html
                assert 'Main Studio' in html and 'Other advertiser venue' not in html
                assert json.loads(data.read_text())['2']['location_id']==10
                assert 'Try &lt;outdoor&gt; training' in html and 'Meet your next training partner.' in html
                assert json.loads(data.read_text())['2']['destination_url']=='https://example.test/training'
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
                status,_,html=req('/advertiser_dashboard.php')
                assert status==200 and 'Updated campaign' in html and 'Active campaign' in html and '$1.25' in html
                status,_,html=req('/advertiser_dashboard.php?filter=draft&q=Updated')
                assert status==200 and 'Updated campaign' in html and '<h3>Active campaign</h3>' not in html
                assert 'Edit draft' in html and 'campaign_edit.php?id=2' in html
                status,_,html=req('/advertiser_dashboard.php?q=%25')
                assert status==200 and 'No matching campaigns' in html
                status,_,html=req('/advertiser_dashboard.php?_metrics=missing')
                assert status==200 and 'metrics are currently unavailable' in html and 'Updated campaign' in html
                status,_,html=req('/advertiser_dashboard.php',client='foreign')
                assert status==200 and 'Your first campaign starts here' in html and 'Updated campaign' not in html
                status,_,html=req('/advertiser_dashboard.php',client='staff')
                assert status==200 and 'Ask the owner' in html and 'Edit draft' not in html
                action={'csrf_token':csrf,'campaign_id':'2','action':'archive'}
                assert req('/advertiser_dashboard.php',{**action,'csrf_token':'wrong'})[0]==403
                assert req('/advertiser_dashboard.php',action,client='staff')[0]==403
                # Foreign caller has valid own token but cannot manage advertiser9's row.
                _,_,foreign_html=req('/campaign_edit.php',client='foreign')
                foreign_csrf=token(foreign_html,'csrf_token')
                assert req('/advertiser_dashboard.php',{**action,'csrf_token':foreign_csrf},client='foreign')[0]==404
                req('/advertiser_dashboard.php?action=archive&campaign_id=2')
                assert json.loads(data.read_text())['2']['status']=='draft'
                assert req('/advertiser_dashboard.php',action)[0]==303
                assert json.loads(data.read_text())['2']['status']=='archived'
                status,_,html=req('/advertiser_dashboard.php?filter=archived')
                assert status==200 and 'Updated campaign' in html and 'Restore draft' in html
                assert req('/advertiser_dashboard.php',action)[0]==409
                assert req('/advertiser_dashboard.php',{**action,'action':'restore'})[0]==303
                assert json.loads(data.read_text())['2']['status']=='draft'
                assert req('/advertiser_dashboard.php',{**action,'campaign_id':'1'})[0]==409
                assert json.loads(data.read_text())['1']['status']=='active'
                status,location,_=req('/advertiser_dashboard.php',
                    {**action,'action':'duplicate','campaign_id':'1'})
                assert status==303 and location=='campaign_edit.php?id=3'
                duplicate=json.loads(data.read_text())['3']
                assert duplicate['status']=='draft' and duplicate['advertiser_id']==9
                assert duplicate['name']=='Active campaign (copy)'
                assert json.loads(data.read_text())['1']['status']=='active'
                _,_,html=req('/campaign_edit.php?id=2')
                revision=token(html,'revision')
                assert req('/campaign_edit.php?id=2',{**valid,'name':'Updated campaign','location_id':'11','revision':revision})[0]==303
                assert json.loads(data.read_text())['2']['location_id']==11
                _,_,html=req('/campaign_edit.php?id=2')
                revision=token(html,'revision')
                assert req('/campaign_edit.php?id=2',{**valid,'name':'Updated campaign','location_id':'','revision':revision})[0]==303
                assert json.loads(data.read_text())['2']['location_id'] is None
                _,_,fresh=req('/campaign_edit.php')
                auto={**valid,'name':'Autosaved draft','autosave':'1','create_key':token(fresh,'create_key')}
                code,_,reply=req('/campaign_edit.php',auto)
                assert code==200,reply
                confirmation=json.loads(reply)
                assert confirmation['id']==4
                assert req('/campaign_edit.php',auto)[2]==reply
                assert len(json.loads(data.read_text()))==4
                code,_,reply=req('/campaign_edit.php?id=4',{**auto,'revision':confirmation['revision'],'name':'Autosaved update'})
                assert code==200 and json.loads(reply)['revision']!=confirmation['revision']
                assert req('/campaign_edit.php?id=4',{**auto,'revision':confirmation['revision']})[0]==409
                assert req('/campaign_edit.php',{**auto,'name':'Changed creation'})[0]==409
                assert req('/campaign_edit.php?id=4',{**auto,'csrf_token':'bad'})[0]==403
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
