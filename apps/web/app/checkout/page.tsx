export const dynamic = "force-dynamic";

/**
 * Jalur checkout — halaman yang paling banyak diatur dokumentasi, dan semuanya
 * berbicara tentang sisi server, bukan tentang layar ini:
 *   1. `POST /checkout/sessions` membuat snapshot keranjang + alamat + kurir,
 *      lalu harga/diskon/pajak/ongkir/stok/kuota DIHITUNG ULANG di server dari
 *      session yang tervalidasi; total dari browser tidak pernah dibaca
 *      (Doc 01 §4 butir 7, Doc 12 §2.1/§2.2).
 *   2. `POST /orders` membuat order + invoice dalam SATU transaksi SQL dengan
 *      reservasi stok (K1) / kuota batch (K2) dan `Idempotency-Key` wajib
 *      (Doc 01 §6.3, §7.1, Doc 12 §3.4).
 *   3. Pembayaran dipegang gateway (hosted); kartu/QRIS tidak pernah disimpan
 *      (Doc 01 §9.3).
 * Bentuk panggilan route ini ke API (langsung vs BFF) menunggu ADR-007 `Proposed`.
 * Kill switch `checkout.enabled` (Doc 01 §16 / Task 0.10) nanti dibaca di sini lewat
 * server component, bukan dari cookie klien.
 */
export default function CheckoutPage() {
  return (
    <main id="konten" className="mx-auto flex max-w-content flex-col gap-2 p-6">
      <h1 className="text-2xl font-semibold text-ink">Checkout</h1>
      <p className="max-w-prose text-sm text-ink-muted">
        Kerangka Sprint 0: tidak ada stepper, tarif, atau ringkasan biaya contoh.
        Semua nominal dirender dari hasil re-pricing server lewat klien ter-generate
        terhadap <code>/api/v1/checkout/sessions</code> dan <code>/api/v1/orders</code>.
      </p>
    </main>
  );
}
