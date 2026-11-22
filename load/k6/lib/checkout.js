import { check } from 'k6';
import { CUSTOMERS, PASSWORD } from './config.js';
import { WebSession } from './session.js';

// Alamat yang sama dengan e2e/tests/journeys.spec.ts; DemoShippingSeeder memberi tarif ke tujuan ini.
const ADDRESS = {
  recipient: 'Load Test', phone: '081234567890', province: 'DKI Jakarta', city: 'Jakarta Selatan',
  district: 'Tebet', postal_code: '12810', address_line: 'Jalan Uji Beban Nomor 24',
};

function json(res) {
  try {
    return res.json();
  } catch (_) {
    return null;
  }
}

export function variantId(session, slug) {
  const res = session.patient('GET', `/products/${slug}`, undefined, 'GET /products/{slug}');
  const body = json(res);
  if (res.status !== 200 || !body || !body.variants || !body.variants.length) {
    throw new Error(`produk ${slug} tidak ditemukan (${res.status}); jalankan LoadTestSeeder dulu`);
  }
  return body.variants[0].id;
}

export function login(email, patient) {
  const session = new WebSession();
  const res = session.login(email, PASSWORD, patient);
  check(res, { 'login 200': (r) => r.status === 200 });
  return res.status === 200 ? session : null;
}

export function ensureAddress(session, patient) {
  const call = patient ? 'patient' : 'request';
  const list = json(session[call]('GET', '/me/addresses', undefined, 'GET /me/addresses'));
  if (list && list.data && list.data.length) {
    const preferred = list.data.find((a) => a.is_default) || list.data[0];
    return preferred.id;
  }
  const created = json(session.mutate('POST', '/me/addresses', ADDRESS, 'POST /me/addresses', patient));
  return created ? created.id : null;
}

export function clearCart(session, patient) {
  const call = patient ? 'patient' : 'request';
  const cart = json(session[call]('GET', '/cart', undefined, 'GET /cart'));
  if (!cart || !cart.groups) return;
  for (const group of cart.groups) {
    for (const item of group.items) session[call]('DELETE', `/cart/items/${item.id}`, undefined, 'DELETE /cart/items/{id}');
  }
}

// Keranjang → checkout session → opsi kirim → pilih kurir. Mengembalikan session checkout siap
// dipesan, atau null bila salah satu langkah gagal (check mencatat langkah mana).
export function prepareCheckout(session, variant, addressId, patient) {
  const call = patient ? 'patient' : 'request';
  const added = session.mutate('POST', '/cart/items', { variant_id: variant, quantity: 1 }, 'POST /cart/items', patient);
  if (!check(added, { 'add to cart 2xx': (r) => r.status === 200 || r.status === 201 })) return null;

  const cart = json(session[call]('GET', '/cart', undefined, 'GET /cart'));
  if (!check(cart, { 'cart has items_hash': (c) => c && typeof c.items_hash === 'string' })) return null;

  const created = session.mutate('POST', '/checkout/sessions', { shipping_address_id: addressId, expected_items_hash: cart.items_hash }, 'POST /checkout/sessions', patient);
  const checkout = json(created);
  if (!check(created, { 'checkout session 201': (r) => r.status === 201 || r.status === 200 }) || !checkout) return null;

  const optionsRes = session[call]('POST', `/checkout/sessions/${checkout.id}/shipping-options`, {}, 'POST /checkout/sessions/{id}/shipping-options');
  const options = json(optionsRes);
  const quoted = check(options, { 'shipping options quoted': (o) => o && o.groups && o.groups.length > 0 && o.groups.every((g) => g.options.length > 0) });
  if (!quoted) return null;

  const selections = options.groups.map((g) => ({ group_index: g.group_index, courier_code: g.options[0].courierCode, service_code: g.options[0].serviceCode }));
  const selected = session.mutate('PUT', `/checkout/sessions/${checkout.id}/shipping-selection`, { selections }, 'PUT /checkout/sessions/{id}/shipping-selection', patient);
  const ready = json(selected);
  if (!check(selected, { 'shipping selected 200': (r) => r.status === 200 }) || !ready) return null;

  return { id: checkout.id, addressId, fakePayment: ready.fake_payment_available === true };
}

export function placeOrder(session, checkout) {
  return session.mutate('POST', '/orders', { checkout_session_id: checkout.id, shipping_address_id: checkout.addressId }, 'POST /orders');
}

export function captureFakePayment(session, order) {
  return session.mutate('POST', `/orders/${order.id}/fake-payment`, { payment_intent_id: order.payment_intent.id }, 'POST /orders/{order}/fake-payment');
}

export function accountEmails(count) {
  const n = Math.min(count, CUSTOMERS);
  const out = [];
  for (let i = 1; i <= n; i++) out.push(i);
  return out;
}

export { json };
