import http from 'node:http';

const key = process.env.ELZARA_STOREFRONT_PROXY_KEY;
if (!key || key.length < 32) throw new Error('A storefront proxy key of at least 32 characters is required');

function serve(port, appHost, appPort, isStorefront) {
  return http.createServer((req, res) => {
    const api = /^\/(api\/|sanctum\/)/.test(req.url ?? '');
    const headers = { ...req.headers };
    delete headers['x-elzara-storefront-key'];
    delete headers['x-elzara-shopper-ip'];
    headers['x-real-ip'] = req.socket.remoteAddress;
    if (api && isStorefront) {
      headers['x-elzara-storefront-key'] = key;
      headers['x-elzara-shopper-ip'] = req.socket.remoteAddress;
    }
    const hostname = api ? process.env.E2E_PROXY_API_HOST : appHost;
    const upstream = http.request({ hostname: hostname ?? '127.0.0.1', port: api ? 8081 : appPort, path: req.url, method: req.method, headers }, (response) => {
      res.writeHead(response.statusCode ?? 502, response.headers);
      response.pipe(res);
    });
    upstream.on('error', () => { res.writeHead(502); res.end('Upstream unavailable'); });
    req.on('aborted', () => upstream.destroy());
    req.pipe(upstream);
  }).listen(port, process.env.E2E_PROXY_BIND ?? '127.0.0.1', () => console.log(`E2E proxy ready on port ${port}`));
}

serve(3080, process.env.E2E_PROXY_WEB_HOST, 3000, true);
serve(3081, process.env.E2E_PROXY_ADMIN_HOST, 3001, false);
