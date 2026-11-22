// Kontensi stok (P0.24): N pembeli menyiapkan checkout untuk varian berstok kecil di setup(),
// lalu semua VU memesan bersamaan. Bukti yang diharapkan: order sukses <= stok, sisanya ditolak
// 409/422, tanpa 5xx. Stok di-reset oleh LoadTestSeeder (LOADTEST_CONTENTION_STOCK).
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import exec from 'k6/execution';
import { CUSTOMERS, customerEmail, int, requirePassword } from './lib/config.js';
import { WebSession } from './lib/session.js';
import { clearCart, ensureAddress, placeOrder, prepareCheckout, variantId } from './lib/checkout.js';

const STOCK = int('CONTENTION_STOCK', 10);
const BUYERS = Math.min(int('CONTENTION_BUYERS', 30), CUSTOMERS);
const SLUG = __ENV.CONTENTION_SLUG || 'load-contention-plush';
const placed = new Counter('elzara_contention_orders_placed');
const rejected = new Counter('elzara_contention_orders_rejected');

export const options = {
  setupTimeout: '10m',
  scenarios: { contention: { executor: 'per-vu-iterations', vus: BUYERS, iterations: 1, maxDuration: '2m' } },
  thresholds: {
    elzara_contention_orders_placed: [`count<=${STOCK}`, 'count>0'],
    elzara_server_errors: ['count==0'],
    'checks{phase:order}': ['rate==1'],
  },
};

export function setup() {
  requirePassword();
  const variant = variantId(new WebSession(), SLUG);
  const buyers = [];
  for (let i = 1; i <= BUYERS; i++) {
    const session = new WebSession(new http.CookieJar());
    const res = session.login(customerEmail(i), __ENV.LOADTEST_PASSWORD, true);
    if (res.status !== 200) throw new Error(`login ${customerEmail(i)} gagal (${res.status})`);
    clearCart(session, true);
    const checkout = prepareCheckout(session, variant, ensureAddress(session, true), true);
    if (!checkout) throw new Error(`penyiapan checkout ${customerEmail(i)} gagal`);
    if (!checkout.fakePayment) throw new Error('jalur Fake payment tidak aktif; order tidak bisa dibuat di target ini');
    buyers.push({ cookies: session.exportCookies(), checkout });
  }
  return { buyers };
}

export default function (data) {
  const buyer = data.buyers[exec.vu.idInTest - 1];
  const session = WebSession.fromCookies(buyer.cookies);
  session.expected = http.expectedStatuses({ min: 200, max: 399 }, 409, 422);
  const res = placeOrder(session, buyer.checkout);
  check(res, { 'order 201 or stock rejection': (r) => r.status === 201 || r.status === 409 || r.status === 422 }, { phase: 'order' });
  if (res.status === 201) placed.add(1);
  else rejected.add(1, { status: String(res.status) });
}
