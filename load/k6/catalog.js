// Skenario katalog publik (P0.24 / Sprint plan 1.7): daftar produk → paginasi cursor → detail.
// Tanpa login; menembak edge (nginx) yang sama dengan pembeli sungguhan.
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { API, THINK_SECONDS, catalogScenario } from './lib/config.js';
import { rateLimited, serverErrors } from './lib/session.js';

export const options = {
  scenarios: { catalog: catalogScenario() },
  thresholds: {
    checks: ['rate>0.99'],
    http_req_failed: ['rate<0.01'],
    'http_req_duration{name:GET /products}': ['p(95)<800'],
    'http_req_duration{name:GET /products/{slug}}': ['p(95)<800'],
    elzara_server_errors: ['count==0'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

const params = (name) => ({ headers: { Accept: 'application/json' }, tags: { name } });

function get(url, name) {
  const res = http.get(url, params(name));
  if (res.status === 429) rateLimited.add(1);
  if (res.status >= 500 || res.status === 0) serverErrors.add(1);
  return res;
}

export function setup() {
  const res = get(`${API}/products?limit=24`, 'GET /products');
  if (res.status !== 200 || !res.json('data.0.slug')) {
    throw new Error(`katalog kosong atau tidak terjangkau (${res.status}) di ${API}`);
  }
}

export default function () {
  group('browse catalog', () => {
    const list = get(`${API}/products?limit=24`, 'GET /products');
    check(list, { 'products 200': (r) => r.status === 200 });
    const body = list.status === 200 ? list.json() : { data: [], meta: {} };
    sleep(THINK_SECONDS * Math.random());

    if (body.meta && body.meta.next_cursor) {
      const next = get(`${API}/products?limit=24&cursor=${encodeURIComponent(body.meta.next_cursor)}`, 'GET /products');
      check(next, { 'next page 200': (r) => r.status === 200 });
    }

    if (body.data.length) {
      const pick = body.data[Math.floor(Math.random() * body.data.length)];
      const detail = get(`${API}/products/${pick.slug}`, 'GET /products/{slug}');
      check(detail, { 'product detail 200': (r) => r.status === 200, 'detail has variants': (r) => r.status === 200 && Array.isArray(r.json('variants')) });
    }
  });
  sleep(THINK_SECONDS);
}
