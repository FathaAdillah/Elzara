// Skenario checkout (P0.24 / Sprint plan 1.7): login → keranjang → checkout session → opsi kirim →
// pilih kurir → order → bayar lewat jalur Fake. Satu akun LoadTestSeeder per VU (VU n = akun n).
// Jalur Fake hanya aktif saat APP_ENV local/testing (FakePaymentAvailability); di staging tanpa
// gateway, langkah order dilewati dan dihitung di elzara_checkout_order_skipped.
import { check, group, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import exec from 'k6/execution';
import { CUSTOMERS, THINK_SECONDS, checkoutScenario, customerEmail, requirePassword } from './lib/config.js';
import { WebSession } from './lib/session.js';
import { captureFakePayment, clearCart, ensureAddress, json, login, placeOrder, prepareCheckout, variantId } from './lib/checkout.js';

const ordersPaid = new Counter('elzara_checkout_orders_paid');
const orderSkipped = new Counter('elzara_checkout_order_skipped');
const journey = new Trend('elzara_checkout_journey', true);
const PLACE_ORDER = (__ENV.PLACE_ORDER || 'true') !== 'false';
const SLUG = __ENV.CHECKOUT_SLUG || 'load-checkout-plush';
const SCENARIO = checkoutScenario();

export const options = {
  scenarios: { checkout: SCENARIO },
  thresholds: {
    checks: ['rate>0.99'],
    http_req_failed: ['rate<0.01'],
    'http_req_duration{name:POST /checkout/sessions}': ['p(95)<1500'],
    'http_req_duration{name:POST /orders}': ['p(95)<2000'],
    elzara_server_errors: ['count==0'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  requirePassword();
  const maxVus = SCENARIO.vus || Math.max(...SCENARIO.stages.map((s) => s.target));
  if (maxVus > CUSTOMERS) throw new Error(`VUS ${maxVus} > LOADTEST_CUSTOMERS ${CUSTOMERS}: setiap VU butuh akun sendiri`);
  return { variant: variantId(new WebSession(), SLUG) };
}

let state = null;

export default function (data) {
  if (state === null) {
    const session = login(customerEmail(exec.vu.idInTest), true);
    if (!session) return;
    clearCart(session, true);
    state = { session, addressId: ensureAddress(session, true) };
  }
  const { session, addressId } = state;
  const started = Date.now();

  group('checkout journey', () => {
    const checkout = prepareCheckout(session, data.variant, addressId, false);
    if (!checkout) {
      clearCart(session, false);
      return;
    }
    if (!PLACE_ORDER || !checkout.fakePayment) {
      orderSkipped.add(1);
      clearCart(session, false);
      return;
    }
    const placed = placeOrder(session, checkout);
    const order = json(placed);
    if (!check(placed, { 'order 201': (r) => r.status === 201 }) || !order) return;
    const paid = captureFakePayment(session, order);
    if (check(paid, { 'fake payment 200': (r) => r.status === 200 })) ordersPaid.add(1);
  });

  journey.add(Date.now() - started);
  sleep(THINK_SECONDS);
}
