import { brand } from "@/config/brand";
import { Card } from "@elzara/ui";
import Link from "next/link";
import { getCategories, getProducts } from "@/lib/catalog-api";
import { CatalogMessage, ProductGrid } from "@/components/shop/products";

export default async function HomePage() {
  const [categories, products] = await Promise.all([
    getCategories(),
    getProducts({ limit: 8 }),
  ]);
  return (
    <main
      id="konten"
      tabIndex={-1}
      className="mx-auto max-w-content space-y-12 px-6 py-8"
    >
      <section
        aria-labelledby="hero-title"
        className="relative overflow-hidden rounded-card bg-brand-ink p-8 sm:p-12"
      >
        <div className="relative z-10 max-w-prose space-y-6">
          <p className="text-xs font-semibold uppercase tracking-widest text-brand-text">
            Ready stock / Pre-order / Request
          </p>
          <h1
            id="hero-title"
            className="text-6xl font-semibold tracking-tight sm:text-7xl"
          >
            {brand.name}
          </h1>
          {brand.tagline && (
            <p className="text-xl text-ink-muted">{brand.tagline}</p>
          )}
          <Link
            href="/catalog"
            className="inline-flex min-h-touch items-center gap-8 rounded-badge bg-brand px-6 py-3 font-medium text-on-brand"
          >
            Jelajahi katalog <span aria-hidden="true">↗</span>
          </Link>
        </div>
        <span
          aria-hidden="true"
          className="pointer-events-none absolute -right-6 top-0 hidden text-[20rem] leading-none text-brand/20 sm:block"
        >
          ✳
        </span>
      </section>
      <nav
        aria-label="Belanja menurut mode"
        className="grid gap-4 sm:grid-cols-2"
      >
        <Card className="border border-divider shadow-none">
          <Link
            className="flex min-h-touch items-center justify-between gap-4"
            href="/catalog?kind=rts"
          >
            <span>
              <span className="block text-xs uppercase tracking-widest text-ink-muted">
                Siap dikirim
              </span>
              <span className="text-xl font-semibold">Ready stock</span>
            </span>
            <span aria-hidden="true">↗</span>
          </Link>
        </Card>
        <Card className="border border-divider shadow-none">
          <Link
            className="flex min-h-touch items-center justify-between gap-4"
            href="/catalog?kind=pre_order"
          >
            <span>
              <span className="block text-xs uppercase tracking-widest text-ink-muted">
                Pesan lebih awal
              </span>
              <span className="text-xl font-semibold">Pre-order</span>
            </span>
            <span aria-hidden="true">↗</span>
          </Link>
        </Card>
      </nav>
      {categories.length > 0 && (
        <nav
          aria-label="Kategori"
          className="flex flex-wrap items-center gap-3"
        >
          <h2 className="mr-3 text-sm font-medium">Jelajahi kategori</h2>
          {categories.map((category) => (
            <Link
              key={category.slug}
              href={`/catalog?category=${encodeURIComponent(category.slug)}`}
              className="inline-flex min-h-touch items-center rounded-badge border border-control-border px-4 text-sm"
            >
              {category.name}
            </Link>
          ))}
        </nav>
      )}
      <section aria-labelledby="latest-title" className="space-y-6">
        <div className="flex items-center justify-between gap-4">
          <div>
            <p className="mb-2 text-xs uppercase tracking-widest text-brand-text">
              Dari katalog kami
            </p>
            <h2
              id="latest-title"
              className="text-3xl font-semibold tracking-tight"
            >
              Terbaru
            </h2>
          </div>
          <Link
            className="inline-flex min-h-touch items-center text-sm font-medium underline underline-offset-4"
            href="/catalog"
          >
            Lihat semua ↗
          </Link>
        </div>
        {products?.data?.data.length ? (
          <ProductGrid products={products.data.data} />
        ) : (
          <CatalogMessage>
            {products?.data
              ? "Belum ada produk. Silakan kembali lagi nanti."
              : "Katalog sedang tidak tersedia. Silakan coba kembali sebentar lagi."}
          </CatalogMessage>
        )}
      </section>
    </main>
  );
}
