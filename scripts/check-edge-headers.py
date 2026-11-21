#!/usr/bin/env python3
"""Check the real entry point without following redirects (including error responses)."""
import argparse
import urllib.error
import urllib.request


def check(base, host, path, api, https):
    request = urllib.request.Request(base.rstrip('/') + path, headers={'Host': host})
    opener = urllib.request.build_opener(type('NoRedirect', (urllib.request.HTTPRedirectHandler,), {
        'redirect_request': lambda *args: None,
    })())
    try:
        response = opener.open(request, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        headers = response.headers
        expected = {
            'X-Content-Type-Options': 'nosniff',
            'X-Frame-Options': 'DENY',
            'Referrer-Policy': 'strict-origin-when-cross-origin',
            'Permissions-Policy': 'camera=(), microphone=(), geolocation=(self), browsing-topics=()',
        }
        if https:
            expected['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains; preload'
        else:
            assert not headers.get_all('Strict-Transport-Security'), 'HSTS on local HTTP'
        for name, value in expected.items():
            assert headers.get_all(name) == [value], (host, path, name, headers.get_all(name))
        policies = headers.get_all('Content-Security-Policy')
        assert policies and len(policies) == 1, (host, path, policies)
        directives = dict((parts[0], parts[1:]) for directive in policies[0].split(';') if (parts := directive.split()))
        assert directives['frame-ancestors'] == ["'none'"]
        assert directives['object-src'] == ["'none'"]
        if api:
            assert directives['default-src'] == ["'none'"]
            assert directives['base-uri'] == ["'none'"]
        else:
            assert directives['default-src'] == ["'self'"]
            assert directives['script-src'] == ["'self'", "'unsafe-inline'"]
            assert directives['form-action'] == ["'self'"]
            assert "'self'" in directives['connect-src']
        assert response.status < 500, (host, path, response.status)
        print(f'PASS {host}{path} status={response.status} policy={"api/media" if api else "frontend"}')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', default='http://localhost:8080')
    parser.add_argument('--web-host', default='localhost')
    parser.add_argument('--admin-host', default='admin.localhost')
    parser.add_argument('--https-edge', action='store_true', help='TLS or private Dokploy HTTPS listener')
    args = parser.parse_args()
    for host in (args.web_host, args.admin_host):
        for path in ('/', '/login', '/not-a-real-page', '/nginx-healthz', '/api/v1/health', '/api/v1/not-a-real-route', '/api/v1/admin/auth/session', '/sanctum/csrf-cookie'):
            check(args.base_url, host, path, path.startswith(('/api/', '/sanctum/', '/nginx-healthz')), args.https_edge)
    print('PASS edge headers: both surfaces, redirects, missing routes and API responses')


if __name__ == '__main__':
    main()
