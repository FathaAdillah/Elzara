import http from 'k6/http';
import { sleep } from 'k6';
import { Counter } from 'k6/metrics';
import { API, BASE_URL } from './config.js';

export const rateLimited = new Counter('elzara_rate_limited');
export const serverErrors = new Counter('elzara_server_errors');

// UUID v4 untuk Idempotency-Key (pola di openapi.yaml#/components/parameters/IdempotencyKey).
export function uuidv4() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

function track(res) {
  if (res.status === 429) rateLimited.add(1);
  if (res.status >= 500 || res.status === 0) serverErrors.add(1);
  return res;
}

// Klien sesi web seperti storefront (apps/web/lib/session.ts): cookie elzara_web_session +
// XSRF-TOKEN, header X-XSRF-TOKEN di setiap mutasi. `jar` opsional supaya setup() bisa
// menyiapkan banyak sesi terpisah lalu menyerahkannya ke VU.
export class WebSession {
  constructor(jar) {
    this.jar = jar || http.cookieJar();
    this.expected = http.expectedStatuses({ min: 200, max: 399 });
  }

  xsrf() {
    const values = this.jar.cookiesForURL(BASE_URL)['XSRF-TOKEN'];
    return values && values.length ? decodeURIComponent(values[values.length - 1]) : '';
  }

  params(name, extra) {
    const headers = Object.assign({ Accept: 'application/json', 'Content-Type': 'application/json', Origin: BASE_URL, Referer: `${BASE_URL}/` }, extra || {});
    const token = this.xsrf();
    if (token) headers['X-XSRF-TOKEN'] = token;
    return { headers, jar: this.jar, tags: { name }, responseCallback: this.expected };
  }

  request(method, path, body, name, extra) {
    const url = path.startsWith('http') ? path : `${API}${path}`;
    const payload = body === undefined ? null : JSON.stringify(body);
    return track(http.request(method, url, payload, this.params(name, extra)));
  }

  // Dipakai di setup() saja: tunggu Retry-After saat 429 supaya penyiapan data tidak gagal
  // karena rate limit, sementara 429 di fase beban tetap dihitung sebagai hasil.
  patient(method, path, body, name, extra) {
    for (let attempt = 0; attempt < 6; attempt++) {
      const res = this.request(method, path, body, name, extra);
      if (res.status !== 429) return res;
      sleep(Math.min(parseInt(res.headers['Retry-After'] || '2', 10) || 2, 30));
    }
    return this.request(method, path, body, name, extra);
  }

  mutate(method, path, body, name, patient) {
    const call = patient ? this.patient.bind(this) : this.request.bind(this);
    return call(method, path, body, name, { 'Idempotency-Key': uuidv4() });
  }

  login(email, password, patient) {
    const call = patient ? this.patient.bind(this) : this.request.bind(this);
    call('GET', `${BASE_URL}/sanctum/csrf-cookie`, undefined, 'GET /sanctum/csrf-cookie');
    return call('POST', '/auth/login', { email, password }, 'POST /auth/login');
  }

  exportCookies() {
    const out = {};
    const cookies = this.jar.cookiesForURL(BASE_URL);
    for (const name of Object.keys(cookies)) out[name] = cookies[name][cookies[name].length - 1];
    return out;
  }

  static fromCookies(cookies) {
    const jar = http.cookieJar();
    jar.clear(BASE_URL);
    for (const name of Object.keys(cookies)) jar.set(BASE_URL, name, cookies[name], { path: '/' });
    return new WebSession(jar);
  }
}
