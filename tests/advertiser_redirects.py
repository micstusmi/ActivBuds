#!/usr/bin/env python3
"""Exercise real advertiser pages with synthetic DB/session collaborators only."""
import contextlib
import http.client
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
from urllib.parse import urlencode

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
DB = '''<?php
class FixtureResult {
    public $num_rows; private $rows;
    function __construct($rows) {$this->rows=$rows; $this->num_rows=count($rows);}
    function fetch_assoc() {return array_shift($this->rows);}
}
class FixtureStatement {
    public $num_rows=0; public $insert_id=7; public $error=''; private $sql;
    function __construct($sql) {$this->sql=$sql;}
    function bind_param($types, &...$values) {}
    function execute() {return true;}
    function store_result() {}
    function close() {}
    function get_result() {
        if (str_contains($this->sql, 'password_hash')) return new FixtureResult([[
            'id'=>7,'advertiser_id'=>9,'name'=>'Fixture','role'=>'owner',
            'advertiser_name'=>'Synthetic venue',
            'password_hash'=>password_hash('fixture-password', PASSWORD_DEFAULT)
        ]]);
        if (str_contains($this->sql, 'SUM(impressions)')) return new FixtureResult([
            ['impressions'=>10,'clicks'=>2,'spend'=>1.25]
        ]);
        return new FixtureResult([]);
    }
}
class FixtureConnection {function prepare($sql) {return new FixtureStatement($sql);}}
if (basename($_SERVER['SCRIPT_NAME']) === 'advertiser_dashboard.php'
    && !isset($_SESSION['adv_user_id'], $_SESSION['adv_advertiser_id'])) {
    throw new RuntimeException('Dashboard accessed DB before authentication');
}
$conn=new FixtureConnection();
'''
ROUTER = '''<?php
$path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$allowed=['/advertiser_login.php','/advertiser_register.php','/advertiser_dashboard.php'];
if (!in_array($path,$allowed,true)) {http_response_code(404); exit;}
session_start(); $_SESSION=[];
if (($_GET['_fixture'] ?? '') === 'signed-in') {
    $_SESSION=['adv_user_id'=>7,'adv_advertiser_id'=>9,'adv_user_name'=>'Fixture',
              'adv_advertiser_name'=>'Synthetic venue'];
}
session_write_close();
$_SERVER['SCRIPT_NAME']=$path;
require __DIR__.$path;
'''

def main():
    count = 0
    with tempfile.TemporaryDirectory(prefix='activbuds-http-') as folder:
        root = Path(folder)
        for name in ('advertiser_login.php', 'advertiser_register.php', 'advertiser_dashboard.php'):
            shutil.copyfile(ROOT / name, root / name)
        (root / 'includes').mkdir()
        (root / 'sessions').mkdir()
        (root / 'includes/db.php').write_text(DB)
        (root / 'includes/header.php').write_text('<!doctype html><html><body><main>fixture-shell\n')
        (root / 'includes/footer.php').write_text('</main></body></html>')
        (root / 'router.php').write_text(ROUTER)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        with (root / 'server.log').open('w+') as log:
            server = subprocess.Popen([PHP, '-d', 'output_buffering=0', '-d', 'display_errors=1',
                '-d', 'session.save_path=' + str(root / 'sessions'), '-S', f'127.0.0.1:{port}',
                '-t', str(root), str(root / 'router.php')], stdout=log, stderr=log)
            try:
                for _ in range(100):
                    if server.poll() is not None:
                        raise AssertionError('PHP HTTP server could not start')
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.1):
                            break
                    except OSError:
                        time.sleep(.02)
                else:
                    raise AssertionError('PHP HTTP server did not become ready')

                def request(path, fields=None, signed=False, redirect=None):
                    nonlocal count
                    if signed:
                        path += '?_fixture=signed-in'
                    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
                    try:
                        conn.request('POST' if fields is not None else 'GET', path,
                            urlencode(fields) if fields is not None else None,
                            {'Content-Type':'application/x-www-form-urlencoded'})
                        response = conn.getresponse()
                        body = response.read().decode()
                        assert not any(x in body for x in ('Warning:', 'Fatal error:', 'Notice:')), body
                        if redirect:
                            assert response.status == 302, (path,response.status,body)
                            assert response.getheader('Location') == redirect
                            assert 'fixture-shell' not in body, 'HTML emitted before redirect'
                        else:
                            assert response.status == 200, (path,response.status,body)
                            assert body.count('fixture-shell') == 1
                            assert '</html>' in body
                        count += 1
                        return body
                    finally:
                        conn.close()

                request('/advertiser_dashboard.php', redirect='advertiser_login.php')
                request('/advertiser_login.php', signed=True, redirect='advertiser_dashboard.php')
                request('/advertiser_register.php', signed=True, redirect='advertiser_dashboard.php')
                request('/advertiser_login.php')
                request('/advertiser_register.php')
                request('/advertiser_login.php', {'email':'fixture@example.test','password':'fixture-password'},
                        redirect='advertiser_dashboard.php')
                body=request('/advertiser_login.php', {'email':'fixture@example.test','password':'wrong'})
                assert 'Invalid email or password.' in body
                request('/advertiser_register.php', {'type':'gym','advertiser_name':'Synthetic venue',
                    'user_name':'Fixture','email':'fixture@example.test',
                    'password':'fixture-password','password2':'fixture-password'},
                    redirect='advertiser_dashboard.php')
                body=request('/advertiser_register.php', {'type':'gym'})
                assert 'Please fill in all required fields.' in body
                body=request('/advertiser_dashboard.php', signed=True)
                assert 'Total impressions' in body and '$1.25' in body
                print(f'PASS {count} actual HTTP cases with PHP output buffering disabled; '
                      'synthetic DB/session only, no live database or deployment')
            finally:
                server.terminate()
                with contextlib.suppress(subprocess.TimeoutExpired):
                    server.wait(timeout=3)
                if server.poll() is None:
                    server.kill()
                    server.wait()

if __name__ == '__main__':
    main()
