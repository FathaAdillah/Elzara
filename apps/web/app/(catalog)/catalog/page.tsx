import { Badge } from "@elzara/ui";

/**
 * Stub rute katalog. Route group `(catalog)` tidak menambah segmen URL, jadi halaman
 * ini hidup di `/catalog` (nama yang dipakai checklist ganti-brand Doc 04 §11 butir 9).
 * `app/page.tsx` tetap memegang `/` — keduanya tidak bertabrakan.
 *
 * DATA ASALNYA DARI MANA: `GET /api/v1/products` (publik, Doc 12 §2.1) dan
 * `/products/{slug}` untuk PDP, dipanggil lewat klien ter-generate di server component
 * (Doc 04 §6), dengan pagination CURSOR — bukan offset (Doc 12 §3.3). Facet & pencarian
 * akan dibaca dari indeks Elasticsearch (`SearchProductsQuery`), yang bukan sumber kebenaran
 * (Doc 01 §3.3) sehingga angkanya tidak boleh dipakai untuk memutuskan stok.
 */
export default function CatalogPage() {
  return (
    <section className="flex flex-col gap-2">
      <h1 className="text-2xl font-semibold text-ink">Katalog</h1>
      <p className="max-w-prose text-sm text-ink-muted">
        Belum ada grid produk dan tidak akan pernah ada produk contoh di repo. Isinya
        dirender dari respons klien ter-generate terhadap <code>/api/v1/products</code>.
      </p>
      <ul aria-label="Mode pemenuhan" className="flex flex-wrap gap-2">
        <li><Badge tone="ready">Stok siap</Badge></li>
        <li><Badge tone="po">Pre-order</Badge></li>
        <li><Badge tone="quote">Penawaran</Badge></li>
      </ul>
    </section>
  );
}
