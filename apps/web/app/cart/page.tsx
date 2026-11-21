export const dynamic = "force-dynamic";

/**
 * Cart adalah halaman privat: TIDAK pernah masuk cache CDN/ISR (Doc 01 §2.4, Doc 04 §8)
 * dan totalnya selalu hasil hitung ulang server (Doc 01 §4 butir 7) — browser tidak
 * pernah jadi sumber nominal.
 *
 * Kontrak yang menghidupkan halaman ini nanti (Doc 12 §2.1 + §2.2 kelompok A):
 * `GET /cart` (isi + total terhitung server), `POST /cart/items` dan
 * `DELETE /cart/items/{item_id}` — semuanya mutasi, jadi wajib `Idempotency-Key`
 * (Doc 12 §3.4) dan dipanggil lewat klien ter-generate, bukan `fetch` manual.
 */
export default function CartPage() {
  return (
    <main id="konten" tabIndex={-1} className="mx-auto flex max-w-content flex-col gap-2 p-6">
      <h1 className="text-2xl font-semibold text-ink">Keranjang</h1>
      <p className="max-w-prose text-sm text-ink-muted">
        Kerangka Sprint 0: tidak ada baris keranjang contoh. Daftarnya datang dari{" "}
        <code>GET /api/v1/cart</code> melalui klien <code>@elzara/api-client</code>;
        pemisahan per jalur pemenuhan (RTS / pre-order / request) dilakukan server dan
        hanya dirender di sini.
      </p>
    </main>
  );
}
