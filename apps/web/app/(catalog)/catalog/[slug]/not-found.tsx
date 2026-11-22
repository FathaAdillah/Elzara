import Link from "next/link";

export default function ProductNotFound() {
  return (
    <section className="space-y-4 py-12">
      <p className="text-sm text-ink-muted">404 / Produk</p>
      <h1 className="text-3xl font-semibold">Produk tidak ditemukan</h1>
      <p>Produk ini belum tersedia atau sudah tidak ditampilkan.</p>
      <Link
        className="inline-flex min-h-touch items-center underline"
        href="/catalog"
      >
        Kembali ke katalog →
      </Link>
    </section>
  );
}
