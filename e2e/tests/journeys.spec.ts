import { test, expect, type Page } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

async function login(page: Page) {
  await page.getByLabel('Email', { exact: true }).fill('demo.customer@elzara.test');
  await page.getByLabel('Kata sandi', { exact: true }).fill(process.env.DEMO_PASSWORD ?? 'E2e-demo-password!42');
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
}

async function addAndSeeCart(page: Page, name: string) {
  await page.getByRole('button', { name: 'Tambah ke keranjang', exact: true }).click();
  await expect(page.locator('#cart-notice')).toContainText('Ditambahkan ke keranjang.');
  await page.getByRole('link', { name: 'Lihat keranjang', exact: true }).click();
  await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
  const response = await page.request.get('/api/v1/cart');
  expect(response.ok()).toBeTruthy();
  const cart = await response.json();
  expect(cart.groups.flatMap((group: { items: { name: string }[] }) => group.items).some((item: { name: string }) => item.name === name)).toBeTruthy();
}

async function readyStockCheckout(page: Page, recipient: string) {
  await page.goto('/');
  await page.getByRole('navigation', { name: 'Navigasi utama' }).getByRole('link', { name: 'Katalog', exact: true }).click();
  await page.getByRole('link', { name: 'Lihat Lumen Fox Plush', exact: true }).click();
  await expect(page).toHaveURL(/\/catalog\/lumen-fox-plush$/);
  await expect(page.getByRole('heading', { name: 'Lumen Fox Plush', exact: true })).toBeVisible();
  await expect(page.getByText('RTS · Ready stock', { exact: true })).toBeVisible();
  await page.getByRole('radio', { name: 'Mint', exact: true }).check();
  await page.getByRole('radio', { name: 'S', exact: true }).check();
  // Master requires authentication: an anonymous add redirects, it does not retain a guest cart.
  await page.getByRole('button', { name: 'Tambah ke keranjang', exact: true }).click();
  await expect(page).toHaveURL(/\/account\/login\?next=/);
  await login(page);
  await expect(page).toHaveURL(/\/catalog\/lumen-fox-plush$/);
  await page.getByRole('radio', { name: 'Mint', exact: true }).check();
  await page.getByRole('radio', { name: 'S', exact: true }).check();
  await addAndSeeCart(page, 'Lumen Fox Plush');
  await expect(page.getByText('Varian: Mint / S', { exact: true })).toBeVisible();
  await page.getByRole('link', { name: 'Lanjut ke checkout' }).click();
  await expect(page.getByText('Lumen Fox Plush × 1', { exact: true })).toBeVisible();
  if (!(await page.getByLabel('Nama penerima', { exact: true }).isVisible())) {
    await page.locator('summary').filter({ hasText: 'Tambah alamat' }).click();
  }
  for (const [label, value] of Object.entries({
    'Nama penerima': recipient, 'Nomor ponsel Indonesia': '081234567890',
    'Provinsi': 'DKI Jakarta', 'Kota / kabupaten': 'Jakarta Selatan', 'Kecamatan': 'Tebet',
    'Kode pos': '12810', 'Alamat lengkap': 'Jalan E2E Nomor 23',
  })) await page.getByLabel(label, { exact: true }).fill(value);
  await page.getByRole('button', { name: 'Simpan alamat', exact: true }).click();
  await expect(page.getByRole('radio', { name: new RegExp(recipient) })).toBeChecked();
  await page.getByRole('button', { name: 'Buat sesi & lihat ongkir', exact: true }).click();
  const shipping = page.getByRole('radio', { name: /JNE · JNE Regular/ });
  await expect(shipping).toBeVisible();
  const savedSelection = page.waitForResponse((response) => response.request().method() === 'PUT' && response.url().includes('/shipping-selection'));
  await shipping.click();
  const saved = await savedSelection;
  expect(saved.ok()).toBeTruthy();
  const checkout = await saved.json();
  expect(checkout.snapshot.shipping_selection).toEqual([{ group_index: 0, courier_code: 'jne', service_code: 'REG' }]);
  await expect(shipping).toBeChecked();
  await expect(page.getByText(/Ongkir terpilih:/)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Reservasi stok untuk simulasi', exact: true })).toBeEnabled();
  return checkout;
}

test('RTS: home, variant, login, cart and persisted checkout shipping', async ({ page }) => {
  await readyStockCheckout(page, 'E2E Shipping Customer');
  await page.goto('/cart');
  await page.getByRole('button', { name: 'Hapus', exact: true }).click();
  await expect(page.getByText(/Keranjang kosong atau telah kedaluwarsa/)).toBeVisible();
});

test('RTS: place order and complete Fake payment', async ({ page }, testInfo) => {
  const checkout = await readyStockCheckout(page, 'E2E Payment Customer');
  const placed = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/v1/orders');
  await page.getByRole('button', { name: 'Reservasi stok untuk simulasi', exact: true }).click();
  const placedResponse = await placed;
  expect(placedResponse.status()).toBe(201);
  const order = await placedResponse.json();
  expect(order.status).toBe('awaiting_payment');
  expect(order.total).toEqual(checkout.total);
  expect(order.payment_intent).toMatchObject({ provider: 'fake', status: 'requires_action' });
  expect(order.invoice.type).toBe('balance');
  await expect(page.getByText('Stok dipesan untuk simulasi', { exact: true })).toBeVisible();
  await expect(page.getByText(`Pesanan ${order.id}`, { exact: true })).toBeVisible();
  const total = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(order.total.amount_minor);
  await expect(page.getByText(`Total pesanan: ${total}`, { exact: true })).toBeVisible();
  await expect(page.getByText(/^Ongkir terpilih:/)).toHaveCount(0);
  const captured = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/orders/${order.id}/fake-payment`));
  await page.getByRole('button', { name: 'Simulasikan pembayaran', exact: true }).click();
  const capturedResponse = await captured;
  expect(capturedResponse.status()).toBe(200);
  expect(await capturedResponse.json()).toEqual({ order_id: order.id, status: 'paid' });
  await expect(page.getByText('Pembayaran simulasi berhasil', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Simulasikan pembayaran', exact: true })).toHaveCount(0);
  const cart = await page.request.get('/api/v1/cart');
  expect(cart.ok()).toBeTruthy();
  expect((await cart.json()).groups).toEqual([]);
  writeFileSync(join(testInfo.project.outputDir, 'rts-order.json'), JSON.stringify(order));
});

test('PO: batch, deposit checkout, Fake payment and decreased quota', async ({ page }, testInfo) => {
  const batches = await page.request.get('/api/v1/preorder-batches');
  expect(batches.ok()).toBeTruthy();
  const batch = (await batches.json()).data.find((value: { name: string }) => value.name === 'Nova Keychain Pre-order');
  expect(batch).toBeTruthy();
  const detail = await page.request.get(`/api/v1/preorder-batches/${batch.id}`);
  expect(detail.ok()).toBeTruthy();
  const initial = await detail.json();
  const item = initial.items[0];
  await page.goto(`/account/login?next=/preorder/${batch.id}`);
  await login(page);
  await expect(page.getByRole('heading', { name: batch.name, exact: true })).toBeVisible();
  await expect(page.getByText('Status: Terbuka', { exact: true })).toBeVisible();
  await expect(page.getByText(`ETA: ${initial.eta_min_date} – ${initial.eta_max_date}`, { exact: true })).toBeVisible();
  await expect(page.getByTestId('batch-countdown')).toContainText(/hari .*jam .*menit .*detik/);
  const card = page.getByRole('article').first();
  await expect(card.getByText(`Kuota: ${item.remaining} dari ${item.quota} tersisa`, { exact: true })).toBeVisible();
  await expect(card.getByText(`Batas per akun: ${item.max_per_account}`, { exact: true })).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('po-batch.png'), fullPage: true });
  await card.getByRole('button', { name: 'Tambah ke keranjang', exact: true }).click();
  await card.getByRole('link', { name: 'Lihat keranjang', exact: true }).click();
  await page.getByRole('link', { name: 'Lanjut ke checkout' }).click();
  let addresses = await page.request.get('/api/v1/me/addresses');
  expect(addresses.ok()).toBeTruthy();
  let address = (await addresses.json()).data[0];
  if (!address) {
    for (const [label, value] of Object.entries({
      'Nama penerima': 'E2E Preorder Customer', 'Nomor ponsel Indonesia': '081234567890',
      'Provinsi': 'DKI Jakarta', 'Kota / kabupaten': 'Jakarta Selatan', 'Kecamatan': 'Tebet',
      'Kode pos': '12810', 'Alamat lengkap': 'Jalan Preorder Nomor 23',
    })) await page.getByLabel(label, { exact: true }).fill(value);
    await page.getByRole('button', { name: 'Simpan alamat', exact: true }).click();
    await expect(page.getByRole('radio', { name: /E2E Preorder Customer/ })).toBeChecked();
    addresses = await page.request.get('/api/v1/me/addresses');
    address = (await addresses.json()).data[0];
  }
  expect(address).toBeTruthy();
  await page.getByRole('radio', { name: new RegExp(address.recipient) }).check();
  const sessionResponse = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/v1/checkout/sessions');
  await page.getByRole('button', { name: 'Buat sesi pre-order', exact: true }).click();
  expect((await sessionResponse).ok()).toBeTruthy();
  await expect(page.getByRole('radio', { name: /JNE/ })).toHaveCount(0);
  await expect(page.getByText(/^Ongkir terpilih:/)).toHaveCount(0);
  const placed = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/v1/orders');
  await page.getByRole('button', { name: 'Pesan pre-order', exact: true }).click();
  const placedResponse = await placed;
  expect(placedResponse.status()).toBe(201);
  const order = await placedResponse.json();
  expect(order.order_type).toBe('preorder');
  expect(order.invoice.type).toBe('deposit');
  expect(order.total).toEqual(item.price);
  expect(order.invoice.total.amount_minor).toBeGreaterThan(0);
  expect(order.invoice.total.amount_minor).toBeLessThan(order.total.amount_minor);
  const money = (amount: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount);
  await expect(page.getByText(`Total pesanan: ${money(order.total.amount_minor)}`, { exact: true })).toBeVisible();
  await expect(page.getByText(`Deposit dibayar sekarang: ${money(order.invoice.total.amount_minor)}`, { exact: true })).toBeVisible();
  const captured = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().endsWith(`/orders/${order.id}/fake-payment`));
  await page.getByRole('button', { name: 'Simulasikan pembayaran', exact: true }).click();
  expect((await captured).status()).toBe(200);
  await expect(page.getByText('Deposit telah dibayar', { exact: true })).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('po-deposit-paid.png'), fullPage: true });
  writeFileSync(join(testInfo.project.outputDir, 'po-order.json'), JSON.stringify({ ...order, batch_id: batch.id, batch_item_id: item.id, reserved_before: item.quota - item.remaining }));
  await page.goto(`/preorder/${batch.id}`);
  await expect(page.getByRole('article').first().getByText(`Kuota: ${item.remaining - 1} dari ${item.quota} tersisa`, { exact: true })).toBeVisible();

  // The seeded account limit is two; one paid enrollment plus two more must fail.
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
  expect(token).toBeTruthy();
  const headers = () => ({ 'X-XSRF-TOKEN': decodeURIComponent(token!.value), 'Idempotency-Key': crypto.randomUUID(), Accept: 'application/json' });
  const added = await page.request.post('/api/v1/cart/items', { headers: headers(), data: { variant_id: item.variant_id, quantity: 2 } });
  expect(added.ok()).toBeTruthy();
  const cart = await (await page.request.get('/api/v1/cart')).json();
  const checkout = await page.request.post('/api/v1/checkout/sessions', { headers: headers(), data: { shipping_address_id: address.id, expected_items_hash: cart.items_hash } });
  expect(checkout.ok()).toBeTruthy();
  const rejected = await page.request.post('/api/v1/orders', { headers: headers(), data: { checkout_session_id: (await checkout.json()).id, shipping_address_id: address.id } });
  expect(rejected.status()).toBe(422);
  expect((await rejected.json()).code).toBe('BATCH_ACCOUNT_LIMIT_EXCEEDED');
  const afterRejection = await (await page.request.get(`/api/v1/preorder-batches/${batch.id}`)).json();
  expect(afterRejection.items[0].remaining).toBe(item.remaining - 1);
});

test('PO: unknown batch shows not-found UI', async ({ page }) => {
  await page.goto('/preorder/batch_missing');
  await expect(page.getByRole('heading', { name: 'Batch tidak ditemukan', exact: true })).toBeVisible();
});

test('Request Order: catalog filter, badge and PDP information', async ({ page }) => {
  await page.goto('/catalog');
  await page.getByRole('combobox', { name: 'Mode', exact: true }).selectOption('request_order');
  await page.getByRole('button', { name: 'Terapkan filter' }).click();
  await expect(page).toHaveURL(/kind=request_order/);
  const item = page.getByRole('article').filter({ hasText: 'E2E Request Charm' });
  await expect(item.getByText('Quote · Request', { exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Lihat Lumen Fox Plush', exact: true })).toHaveCount(0);
  await item.getByRole('link', { name: 'Lihat E2E Request Charm', exact: true }).click();
  await expect(page).toHaveURL(/\/catalog\/e2e-request-charm$/);
  await expect(page.getByRole('heading', { name: 'E2E Request Charm', exact: true })).toBeVisible();
  await expect(page.getByText('Quote · Request', { exact: true })).toBeVisible();
  await expect(page.getByText('Minta penawaran', { exact: true }).first()).toBeVisible();
  await expect(page.getByText('Request Order: penawaran dikonfirmasi oleh operator.', { exact: true })).toBeVisible();
});

test.fixme('Request Order: submit request, operator quote and payment — no flow on master', async () => {});
