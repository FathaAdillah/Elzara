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
  await expect(page.getByText(`Pesanan ${order.id} ·`, { exact: false })).toBeVisible();
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

test('PO: batch placeholder and supported pre-order product/cart journey', async ({ page }) => {
  await page.goto('/preorder/e2e-batch');
  await expect(page.getByRole('heading', { name: 'Pre-order', exact: true })).toBeVisible();
  await expect(page.getByText('e2e-batch', { exact: true })).toBeVisible();
  await expect(page.getByText(/tidak ada batch contoh di repo/)).toBeVisible();
  await page.goto('/account/login?next=/catalog/nova-keychain');
  await login(page);
  await expect(page).toHaveURL(/\/catalog\/nova-keychain$/);
  await expect(page.getByRole('heading', { name: 'Nova Keychain', exact: true })).toBeVisible();
  await expect(page.getByText('PO · Pre-order', { exact: true })).toBeVisible();
  await addAndSeeCart(page, 'Nova Keychain');
  await expect(page.getByRole('heading', { name: /Pre-order · ETA:/ })).toBeVisible();
});

test.fixme('PO: seeded batch details, quota, ETA and reservation — no batch schema/API/UI on master', async () => {});

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
