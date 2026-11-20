import { brand } from "@/config/brand";

/**
 * Beranda storefront. Sengaja TIDAK ada produk/banner contoh: hero, banner, dan
 * grid adalah `ContentBlock` milik modul Content yang dibaca lewat klien
 * ter-generate (Doc 04 §5 "Hero/banner/kategori = modul Content via API"), dan
 * katalog publik diambil di server component dari `GET /api/v1/products`
 * (Doc 12 §2.1) lalu boleh di-cache SSG/ISR/edge (Doc 04 §8).
 *
 * Aturan yang tidak boleh terbalik di halaman mana pun: cache boleh sedikit basi
 * untuk tampilan, tapi kebenaran stok/kuota TIDAK pernah ada di cache (Doc 01 §6.2,
 * Doc 04 §8) dan nominal uang tidak dihitung di frontend (Doc 01 §4 butir 7).
 */
export default function HomePage() {
  return (
    <main id="konten" className="mx-auto flex max-w-content flex-col gap-3 p-6">
      <h1 className="text-3xl font-semibold text-ink">{brand.name}</h1>
      <p className="max-w-prose text-sm text-ink-muted">
        Kerangka Sprint 0 — belum ada blok konten. Beranda dibangun dari modul Content
        (halaman, blok ber-versi, nav, SEO) lewat klien ter-generate terhadap{" "}
        <code>/api/v1</code>, bukan dari data contoh yang ditulis di komponen.
      </p>
      <p className="max-w-prose text-sm text-ink-muted">
        Jelajah yang akan muncul di sini: <code>/catalog</code>, <code>/preorder/[batch]</code>,
        <code> /cart</code>, <code>/checkout</code>, <code>/account</code>.
      </p>
    </main>
  );
}
