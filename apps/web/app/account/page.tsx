import Link from "next/link";

export const dynamic = "force-dynamic";

/**
 * Area akun customer (halaman "Pesanan Saya" dst., Doc 04 §5). Privat: `no-store`,
 * tidak pernah ter-cache edge/ISR (Doc 01 §2.4).
 *
 * Kontrak sisi customer yang sudah dinormakan (Doc 12 §2.2 kelompok B & C):
 * `GET /me/orders`, `GET /me/orders/{id}`, `GET /me/orders/{id}/tracking`,
 * `GET/POST/PATCH/DELETE /me/addresses`, `GET /me/returns`, `GET /me/quotations/{id}`
 * — semuanya identitas terautentikasi lewat cookie sesi Sanctum `elzara_web_session`
 * (ADR-007 amandemen, Doc 12 §3.1). Form masuk/daftar ada di `/account/login`.
 */
export default function AccountPage() {
  return (
    <main id="konten" tabIndex={-1} className="mx-auto flex max-w-content flex-col gap-2 p-6">
      <h1 className="text-2xl font-semibold text-ink">Akun</h1>
      <p className="max-w-prose text-sm text-ink-muted">
        Kerangka Sprint 0: tanpa data contoh. Daftar pesanan, alamat, retur, dan
        penawaran diterima dari endpoint <code>/me/*</code> lewat klien ter-generate,
        dan setiap respons error wajib menampilkan <code>X-Request-Id</code> agar
        laporan customer-support bisa diselesaikan tanpa membuka log (Doc 12 §3.2).
      </p>
      <a href="/account/login" className="text-sm text-ink underline">
        Masuk atau daftar
      </a>
      <Link href="/account/requests" className="text-sm text-ink underline">
        Pengajuan barang saya
      </Link>
    </main>
  );
}
