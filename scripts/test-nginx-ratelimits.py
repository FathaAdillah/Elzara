#!/usr/bin/env python3
"""Exercise the real edge config. Requires Docker, Python 3 and openssl.

Use --config-dir to test another revision's docker/nginx directory.
"""

import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from http.client import HTTPConnection
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import uuid


def run(*args):
    result = subprocess.run(args, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(f'{args}:\n{result.stdout}')
    return result.stdout.strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config-dir', type=Path, default=Path(__file__).resolve().parents[1] / 'docker/nginx')
    args = parser.parse_args()
    name = 'elzara-edge-test-' + uuid.uuid4().hex[:10]
    containers = []
    failures = []
    with tempfile.TemporaryDirectory(prefix='elzara-edge-') as directory:
        root = Path(directory)
        shutil.copytree(args.config_dir, root / 'nginx')
        (root / 'stub.conf').write_text('''server {
    listen 3000;
    location = /_nuxt/missing.js { return 404; }
    location = /real-ip { return 200 "$http_x_real_ip"; }
    location / { return 200 "stub\\n"; }
}
''')
        (root / 'index.php').write_text('<?php http_response_code(200); echo "api stub";')
        (root / 'certs').mkdir()
        run('openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-subj', '/CN=localhost', '-keyout', str(root / 'certs/localhost-key.pem'),
            '-out', str(root / 'certs/localhost.pem'))
        run('docker', 'network', 'create', name)
        try:
            def start(suffix, *options):
                container = name + '-' + suffix
                containers.append(container)
                run('docker', 'run', '-d', '--name', container, '--network', name, *options)
                return container

            start('stub', '--network-alias', 'web', '--network-alias', 'admin',
                  '--network-alias', 'minio', '-v', f'{root}/stub.conf:/etc/nginx/conf.d/default.conf:ro',
                  'nginx:1.27-alpine')
            start('api', '--network-alias', 'api', '-v', f'{root}/index.php:/app/public/index.php:ro',
                  'php:8.4-fpm-alpine')
            edge = start('edge', '-p', '127.0.0.1::8080',
                         '-e', 'ELZARA_ADMIN_HOST=admin.localhost', '-e', 'ELZARA_EDGE_URL=http://web.localhost',
                         '-e', 'MINIO_PUBLIC_ENDPOINT=http://storage.localhost',
                         '-e', 'NGINX_ENVSUBST_FILTER=^(ELZARA_(ADMIN_HOST|EDGE_URL)|MINIO_PUBLIC_ENDPOINT)$',
                         '-v', f'{root}/nginx:/etc/nginx/elzara:ro',
                         '-v', f'{root}/nginx/edge.conf.template:/etc/nginx/templates/edge.conf.template:ro',
                         '-v', f'{root}/nginx/dev.conf:/etc/nginx/conf.d/default.conf:ro',
                         '-v', f'{root}/certs:/etc/nginx/certs:ro', 'nginx:1.27-alpine')
            dokploy = start('dokploy', '-p', '127.0.0.1::8080',
                            '-e', 'ELZARA_ADMIN_HOST=admin.localhost', '-e', 'ELZARA_EDGE_URL=http://web.localhost',
                            '-e', 'MINIO_PUBLIC_ENDPOINT=http://storage.localhost',
                            '-e', 'NGINX_ENVSUBST_FILTER=^(ELZARA_(ADMIN_HOST|EDGE_URL)|MINIO_PUBLIC_ENDPOINT)$',
                            '-v', f'{root}/nginx:/etc/nginx/elzara:ro',
                            '-v', f'{root}/nginx/edge.conf.template:/etc/nginx/templates/edge.conf.template:ro',
                            '-v', f'{root}/nginx/dokploy.conf:/etc/nginx/conf.d/default.conf:ro', 'nginx:1.27-alpine')
            port = int(run('docker', 'port', edge, '8080/tcp').rsplit(':', 1)[1])
            dokploy_port = int(run('docker', 'port', dokploy, '8080/tcp').rsplit(':', 1)[1])

            def fetch(path, host, headers=None, target=None):
                conn = HTTPConnection('127.0.0.1', target or port, timeout=15)
                try:
                    conn.request('GET', path, headers={'Host': host, **(headers or {})})
                    response = conn.getresponse()
                    body = response.read().decode()
                    return response.status, dict(response.getheaders()), body
                finally:
                    conn.close()

            def request(path, host):
                return fetch(path, host)[:2]

            for attempt in range(60):
                try:
                    if request('/nginx-healthz', 'admin.localhost')[0] == 200:
                        break
                except OSError:
                    pass
                time.sleep(0.25)
            else:
                raise RuntimeError(run('docker', 'logs', edge))

            for attempt in range(60):
                try:
                    if fetch('/nginx-healthz', 'admin.localhost', target=dokploy_port)[0] == 200:
                        break
                except OSError:
                    pass
                time.sleep(0.25)
            else:
                raise RuntimeError(run('docker', 'logs', dokploy))

            # The storefront forwards X-Real-IP to the API as the shopper identity: clients cannot set it,
            # and behind Traefik (dokploy.conf) it is the hop Traefik appended, not a spoofed leftmost one.
            for label, target, headers, expected in (
                ('dev ignores client X-Real-IP', port, {'X-Real-IP': '203.0.113.99', 'X-Forwarded-For': '203.0.113.98'}, None),
                ('dokploy uses Traefik client IP', dokploy_port, {'X-Forwarded-For': '198.51.100.7'}, '198.51.100.7'),
                ('dokploy ignores spoofed hops', dokploy_port, {'X-Real-IP': '203.0.113.99', 'X-Forwarded-For': '203.0.113.98, 198.51.100.7'}, '198.51.100.7'),
            ):
                status, _, body = fetch('/real-ip', 'web.localhost', headers, target)
                ok = status == 200 and body and not body.startswith('203.0.113.') and (expected is None or body == expected)
                print(f'{"PASS" if ok else "FAIL"} {label}: {body!r}', flush=True)
                if not ok:
                    failures.append(label)

            # Validate all entrypoints against the rendered production template.
            for variant in ('dev', 'tls', 'dokploy'):
                run('docker', 'exec', edge, 'sh', '-ec',
                    f"cp /etc/nginx/elzara/{variant}.conf /tmp/entry.conf; "
                    "sed 's@include /etc/nginx/conf.d/\\*.conf;@include /etc/nginx/conf.d/edge.conf; include /tmp/entry.conf;@' /etc/nginx/nginx.conf > /etc/nginx/check.conf; "
                    'nginx -t -c /etc/nginx/check.conf')
                print(f'PASS nginx -t: {variant}.conf', flush=True)

            def burst(label, path, host, count, limited=False):
                with ThreadPoolExecutor(max_workers=120) as pool:
                    responses = list(pool.map(lambda _: request(path, host), range(count)))
                counts = Counter(status for status, _ in responses)
                ok = counts[200] > 0 and (counts[429] > 0 if limited else counts == {200: count})
                print(f'{"PASS" if ok else "FAIL"} {label}: {dict(sorted(counts.items()))}', flush=True)
                if not ok:
                    failures.append(label)

            burst('admin assets', '/_nuxt/chunk.js', 'admin.localhost', 160)
            burst('web assets', '/_next/static/chunk.js', 'web.localhost', 160)
            burst('favicon', '/favicon.ico', 'admin.localhost', 160)
            burst('brand icon', '/brand/elzara-mark.png', 'admin.localhost', 160)
            for path, expected in (('/_nuxt/chunk.js', 200), ('/_nuxt/missing.js', 404)):
                status, headers = request(path, 'admin.localhost')
                ok = status == expected and all(headers.get(key) == value for key, value in {
                    'X-Content-Type-Options': 'nosniff', 'X-Frame-Options': 'DENY',
                    'Referrer-Policy': 'strict-origin-when-cross-origin',
                    'Permissions-Policy': 'camera=(), microphone=(), geolocation=(self), browsing-topics=()',
                    'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: http://storage.localhost; font-src 'self'; connect-src 'self' http://storage.localhost; frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'",
                }.items())
                print(f'{"PASS" if ok else "FAIL"} security headers: {path} ({status})', flush=True)
                if not ok:
                    failures.append('security headers ' + path)
            burst('admin documents limited', '/', 'admin.localhost', 160, limited=True)
            burst('web documents limited', '/', 'web.localhost', 240, limited=True)
            burst('API limited', '/api/test', 'admin.localhost', 240, limited=True)
            time.sleep(3)
            burst('CSRF limited', '/sanctum/csrf-cookie', 'admin.localhost', 240, limited=True)
            burst('webhooks limited', '/api/v1/webhooks/test', 'admin.localhost', 300, limited=True)
            assert request('/api/v1/internal/test', 'admin.localhost')[0] == 404
            if failures:
                raise SystemExit('FAIL: ' + ', '.join(failures))
            print('PASS all nginx rate-limit regression checks', flush=True)
        finally:
            for container in reversed(containers):
                subprocess.run(['docker', 'rm', '-f', container], stdout=subprocess.DEVNULL, check=False)
            subprocess.run(['docker', 'network', 'rm', name], stdout=subprocess.DEVNULL, check=False)


if __name__ == '__main__':
    main()
