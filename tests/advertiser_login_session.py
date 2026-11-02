#!/usr/bin/env python3
"""Real PHP sessions/cookies; synthetic database, no live accounts."""
import http.client
from http.cookies import SimpleCookie
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
from urllib.parse import urlencode

from advertiser_redirects import DB

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')

def main():
    with tempfile.TemporaryDirectory(prefix='activbuds-session-') as folder:
        root = Path(folder)
        sessions = root / 'sessions'
        sessions.mkdir()
        (root / 'includes').mkdir()
        for name in ('advertiser_login.php', 'advertiser_dashboard.php'):
            shutil.copyfile(ROOT / name, root / name)
        (root / 'includes/db.php').write_text(DB)
        (root / 'includes/header.php').write_text('<html><body>fixture-shell')
        (root / 'includes/footer.php').write_text('</body></html>')
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        with (root / 'server.log').open('w+') as log:
            server = subprocess.Popen([PHP, '-d', 'output_buffering=0', '-d', 'display_errors=1',
                '-d', 'session.save_path=' + str(sessions), '-S', f'127.0.0.1:{port}',
                '-t', str(root)], stdout=log, stderr=log)
            try:
                for _ in range(100):
                    if server.poll() is not None:
                        raise AssertionError('PHP server failed to start')
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.1):
                            break
                    except OSError:
                        time.sleep(.02)
                else:
                    raise AssertionError('PHP server did not become ready')

                def request(path, cookie=None, password=None):
                    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
                    headers = {'Content-Type':'application/x-www-form-urlencoded'}
                    if cookie:
                        headers['Cookie'] = 'PHPSESSID=' + cookie
                    try:
                        conn.request('POST' if password is not None else 'GET', path,
                            urlencode({'email':'fixture@example.test','password':password})
                                if password is not None else None, headers)
                        reply = conn.getresponse()
                        body = reply.read().decode()
                        assert '<b>Warning</b>' not in body and '<b>Fatal error</b>' not in body
                        cookies = SimpleCookie()
                        for key, value in reply.getheaders():
                            if key.lower() == 'set-cookie':
                                cookies.load(value)
                        new_id = cookies['PHPSESSID'].value if 'PHPSESSID' in cookies else cookie
                        return reply.status, reply.getheader('Location'), body, new_id
                    finally:
                        conn.close()

                status, _, _, anonymous = request('/advertiser_login.php')
                assert status == 200 and anonymous
                status, _, body, failed_id = request('/advertiser_login.php', anonymous, 'wrong')
                assert status == 200 and 'Invalid email or password.' in body
                assert failed_id == anonymous
                status, location, body, authenticated = request('/advertiser_login.php', anonymous, 'fixture-password')
                assert status == 302 and location == 'advertiser_dashboard.php'
                assert authenticated != anonymous, 'Successful sign-in retained anonymous session ID'
                assert 'fixture-shell' not in body
                assert not (sessions / ('sess_' + anonymous)).exists(), 'Old session file not removed'
                status, _, body, _ = request('/advertiser_dashboard.php', authenticated)
                assert status == 200 and 'Total impressions' in body
                status, location, _, _ = request('/advertiser_dashboard.php', anonymous)
                assert status == 302 and location == 'advertiser_login.php', 'Old cookie retained access'
                status, location, _, _ = request('/advertiser_login.php', authenticated)
                assert status == 302 and location == 'advertiser_dashboard.php'

                _, _, _, throttled = request('/advertiser_login.php')
                for _ in range(5):
                    status, _, body, unchanged = request('/advertiser_login.php', throttled, 'wrong')
                    assert status == 200 and 'Invalid email or password.' in body
                    assert unchanged == throttled
                status, _, body, unchanged = request('/advertiser_login.php', throttled, 'fixture-password')
                assert status == 200 and 'Too many login attempts.' in body
                assert unchanged == throttled
                status, location, _, _ = request('/advertiser_dashboard.php', throttled)
                assert status == 302 and location == 'advertiser_login.php'
                print('PASS: real PHP cookie rotation, old-ID deletion/replay refusal, new-ID access, '
                      'invalid-password and five-attempt throttle controls; synthetic DB only')
            finally:
                server.terminate()
                try:
                    server.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()

if __name__ == '__main__':
    main()
