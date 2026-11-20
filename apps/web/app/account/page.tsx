export const dynamic = "force-dynamic";

/**
 * Area akun customer (halaman "Pesanan Saya" dst., Doc 04 §5). Privat: `no-store`,
 * tidak pernah ter-cache edge/ISR (Doc 01 §2.4).
 *
 * Kontrak sisi customer yang sudah dinormakan (Doc 12 §2.2 kelompok B & C):
 * `GET /me/orders`, `GET /me/orders/{id}`, `GET /me/orders/{id}/tracking`,
 * `GET/POST/PATCH/DELETE /me/addresses`, `GET /me/returns`, `GET /me/quotations/{id}`
 * — semuanya identitas terautentikasi. Cara identitas itu dibawa (cookie sesi HttpOnly
 * lewat BFF vs token short-lived) adalah ADR-007 yang masih `Proposed` (Doc 12 §3.1):
 * halaman ini sengaja tidak membuat form login maupun menyimpan kredensial apa pun.
 */
export default function AccountPage() {
  return (
    <main id="konten" className="mx-auto flex max-w-content flex-col gap-2 p-6">
      <h1 className="text-2xl font-semibold text-ink">Akun</h1>
      <p className="max-w-prose text-sm text-ink-muted">
        Kerangka Sprint 0: tanpa data contoh. Daftar pesanan, alamat, retur, dan
        penawaran diterima dari endpoint <code>/me/*</code> lewat klien ter-generate,
        dan setiap respons error wajib menampilkan <code>X-Request-Id</code> agar
        laporan customer-support bisa diselesaikan tanpa membuka log (Doc 12 §3.2).
      </p>
    </main>
  );
}
