#!/usr/bin/env python3
"""Native rollback check on a NEW private MariaDB datadir, never an existing DB.

Set MARIADB_BASEDIR to an unpacked MariaDB usr directory, PHP_BINARY to PHP,
and optionally PHP_EXTENSION_DIR to matching mysqlnd/mysqli shared modules.
Requires a local Unix socket and MariaDB's install-db helper. Minimal synthetic
schema tests controller behavior; it is not the deployed ActivBuds schema.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
BASE = Path(os.environ['MARIADB_BASEDIR']).resolve()
OPTIONS = []
if os.environ.get('PHP_EXTENSION_DIR'):
    ext = Path(os.environ['PHP_EXTENSION_DIR'])
    OPTIONS = ['-n', '-d', 'extension=' + str(ext / 'mysqlnd.so'),
               '-d', 'extension=' + str(ext / 'mysqli.so')]

SCHEMA = '''
CREATE DATABASE activbuds_native;
USE activbuds_native;
CREATE TABLE advertisers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200),
 type VARCHAR(40), is_franchise INT) ENGINE=InnoDB;
CREATE TABLE advertiser_locations (id INT AUTO_INCREMENT PRIMARY KEY,
 advertiser_id INT, name VARCHAR(200), address_line1 VARCHAR(200),
 address_line2 VARCHAR(200), city VARCHAR(100), state VARCHAR(100),
 postcode VARCHAR(30), country VARCHAR(100)) ENGINE=InnoDB;
CREATE TABLE advertiser_users (id INT AUTO_INCREMENT PRIMARY KEY,
 advertiser_id INT, email VARCHAR(200) UNIQUE, password_hash VARCHAR(255),
 name VARCHAR(200), role VARCHAR(30), is_active INT) ENGINE=InnoDB;
'''
PRELUDE = '''<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn=new mysqli('localhost','root','','',0,getenv('ACTIVBUDS_TEST_SOCKET'));
'''
CONTROLLER = '''<?php
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['type'=>'gym','advertiser_name'=>'Synthetic venue','user_name'=>'Fixture',
 'email'=>'fixture@example.test','password'=>'fixture-password','password2'=>'fixture-password'];
register_shutdown_function(function() {
    $db=new mysqli('localhost','root','','activbuds_native',0,getenv('ACTIVBUDS_TEST_SOCKET'));
    $counts=[];
    foreach (['advertisers','advertiser_locations','advertiser_users'] as $name) {
        $counts[$name]=(int)$db->query('SELECT COUNT(*) n FROM '.$name)->fetch_assoc()['n'];
    }
    file_put_contents(getenv('ACTIVBUDS_TEST_RESULT'),json_encode([
        'counts'=>$counts,'authenticated'=>isset($_SESSION['adv_user_id'])]));
});
require __DIR__.'/advertiser_register.php';
'''

def main():
    env = dict(os.environ)
    libs = str(BASE / 'lib/x86_64-linux-gnu')
    env['LD_LIBRARY_PATH'] = libs + (':' + env['LD_LIBRARY_PATH'] if env.get('LD_LIBRARY_PATH') else '')
    with tempfile.TemporaryDirectory(prefix='activbuds-native-') as folder:
        work = Path(folder)
        data = work / 'data'
        socket = work / 'mysql.sock'
        env['ACTIVBUDS_TEST_SOCKET'] = str(socket)
        env['ACTIVBUDS_TEST_RESULT'] = str(work / 'result.json')
        install = BASE / 'bin/mariadb-install-db'
        command = [str(install), '--no-defaults', '--basedir=' + str(BASE),
                   '--datadir=' + str(data), '--auth-root-authentication-method=normal', '--skip-test-db']
        if os.geteuid() == 0:
            command.append('--user=root')
        init = subprocess.run(command, env=env, capture_output=True, text=True, timeout=60)
        if init.returncode:
            raise RuntimeError('Isolated MariaDB initialization failed: ' + init.stderr[-1000:])
        server_command = [str(BASE / 'sbin/mariadbd'), '--no-defaults',
            '--basedir=' + str(BASE), '--datadir=' + str(data),
            '--socket=' + str(socket), '--pid-file=' + str(work / 'mysql.pid'),
            '--skip-networking', '--log-error=' + str(work / 'mysql.log')]
        if os.geteuid() == 0:
            server_command.append('--user=root')
        server = subprocess.Popen(server_command, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            def php(code):
                result = subprocess.run([PHP, *OPTIONS], input=PRELUDE + code,
                    env=env, capture_output=True, text=True, timeout=10)
                if result.returncode:
                    raise RuntimeError('Native fixture command failed: ' + result.stderr[-1000:])
                return result.stdout

            for _ in range(150):
                if server.poll() is not None:
                    raise RuntimeError('Isolated MariaDB stopped: ' + (work / 'mysql.log').read_text()[-1000:])
                if socket.exists():
                    try:
                        php("echo $conn->server_info;")
                        break
                    except RuntimeError:
                        pass
                time.sleep(.1)
            else:
                raise RuntimeError('Isolated MariaDB did not become ready')
            version = php('echo $conn->server_info;')
            schema = json.dumps(SCHEMA)
            php('$conn->multi_query(' + schema + '); do {if($r=$conn->store_result())$r->free();} '
                'while($conn->more_results() && $conn->next_result());')
            (work / 'includes').mkdir()
            (work / 'sessions').mkdir()
            shutil.copyfile(ROOT / 'advertiser_register.php', work / 'advertiser_register.php')
            (work / 'includes/db.php').write_text(PRELUDE + "$conn->select_db('activbuds_native');")
            (work / 'includes/header.php').write_text('<html><body>')
            (work / 'includes/footer.php').write_text('</body></html>')
            (work / 'controller.php').write_text(CONTROLLER)
            for case in ('success', 'advertiser-failure', 'location-failure', 'owner-failure', 'unsupported-engine'):
                php("$conn->select_db('activbuds_native'); foreach(['advertisers','advertiser_locations',"
                    "'advertiser_users'] as $t) {$conn->query('DROP TRIGGER IF EXISTS fail_'.$t);"
                    "$conn->query('DELETE FROM '.$t);}")
                failed = {'advertiser-failure':'advertisers', 'location-failure':'advertiser_locations',
                          'owner-failure':'advertiser_users'}.get(case)
                if failed:
                    php("$conn->select_db('activbuds_native'); $conn->query(\"CREATE TRIGGER fail_" + failed +
                        ' BEFORE INSERT ON ' + failed + " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'\");")
                if case == 'unsupported-engine':
                    php("$conn->query('ALTER TABLE activbuds_native.advertiser_locations ENGINE=MyISAM');")
                result_file = work / 'result.json'
                result_file.unlink(missing_ok=True)
                response = subprocess.run([PHP, *OPTIONS, '-d', 'output_buffering=0',
                    '-d', 'session.save_path=' + str(work / 'sessions'), str(work / 'controller.php')],
                    env=env, capture_output=True, text=True, timeout=10)
                assert response.returncode == 0 and not response.stderr, (case, response.stderr)
                observed = json.loads(result_file.read_text())
                expected = 1 if case == 'success' else 0
                assert list(observed['counts'].values()) == [expected]*3, (case, observed)
                assert observed['authenticated'] == (case == 'success'), (case, observed)
                if case != 'success':
                    assert 'Your account could not be created. Please try again.' in response.stdout
                    assert 'synthetic failure' not in response.stdout
            print(json.dumps({'result':'PASS','native_cases':5,'server':version,
                'database':'fresh isolated synthetic schema','deployed_schema_verified':False,
                'application_database_contacted':False}))
        finally:
            server.terminate()
            try:
                server.wait(timeout=10)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()

if __name__ == '__main__':
    main()
