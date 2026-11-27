import type { Metadata } from "next";
import Link from "next/link";
import { StorefrontBanner } from "@/components/shop/storefront-banner";
import {
  CategoryTiles,
  ShoppingModes,
} from "@/components/shop/category-navigation";
import { FacetPanel } from "@/components/shop/catalog-filters";
import { CatalogMessage, ProductGrid } from "@/components/shop/products";
import { catalogHref, catalogQuery, type SearchParams } from "@/lib/catalog";
import { getCategories, getProducts } from "@/lib/catalog-api";

type Props = { searchParams: Promise<SearchParams> };

export async function generateMetadata({
  searchParams,
}: Props): Promise<Metadata> {
  const params = await searchParams;
  const filtered = Object.values(params).some(
    (value) => value !== undefined && value !== "",
  );
  return {
    title: "Katalog",
    alternates: { canonical: "/catalog" },
    robots: { index: !filtered, follow: true },
  };
}

export default async function CatalogPage({ searchParams }: Props) {
  const params = await searchParams;
  const parsed = catalogQuery(params);
  const [categories, result] = await Promise.all([
    getCategories(),
    parsed.query ? getProducts(parsed.query) : undefined,
  ]);
  const error =
    parsed.error ||
    (result?.response.status === 422 || result?.response.status === 400
      ? "Filter atau halaman tidak valid. Periksa pilihan Anda atau hapus semua filter."
      : !result?.data
        ? "Katalog sedang tidak tersedia. Silakan coba lagi sebentar lagi."
        : undefined);
  return (
    <div className="space-y-8">
      <nav aria-label="Breadcrumb" className="text-sm text-ink-muted">
        <Link className="inline-flex min-h-touch items-center" href="/">
          Beranda
        </Link>{" "}
        <span aria-hidden="true">/</span> Katalog
      </nav>
      <StorefrontBanner compact />
      <ShoppingModes />
      {categories.length > 0 && (
        <section
          className="rounded-card bg-surface p-5"
          aria-labelledby="catalog-categories"
        >
          <h2
            id="catalog-categories"
            className="mb-4 text-lg font-semibold text-brand-text"
          >
            Kategori produk
          </h2>
          <CategoryTiles categories={categories.slice(0, 8)} />
        </section>
      )}
      <header className="border-b border-divider pb-4">
        <p className="mb-3 text-xs uppercase tracking-widest text-brand-text">
          Temukan pilihan Anda
        </p>
        <h1 className="text-3xl font-semibold tracking-tight">Katalog</h1>
        <p className="mt-3 text-sm text-ink-muted">
          Ready stock, pre-order, dan request — dalam satu tempat.
        </p>
        <Link className="mt-2 inline-flex min-h-touch items-center text-sm font-medium underline" href="/account/requests">
          Ajukan barang yang belum ada di katalog
        </Link>
      </header>
      <div className="grid items-start gap-8 lg:grid-cols-[15rem_1fr]">
        <aside
          aria-label="Pencarian dan filter"
          className="rounded-card border border-divider p-4"
        >
          <FacetPanel categories={categories} params={params} />
        </aside>
        <section aria-label="Hasil katalog" className="min-w-0 space-y-6">
          <div className="flex min-h-touch items-center justify-between gap-4 text-sm">
            <h2 className="font-medium">
              {typeof params.q === "string" && params.q
                ? `Hasil untuk “${params.q}”`
                : params.kind === "pre_order"
                  ? "Pilihan pre-order"
                  : "Semua produk"}
            </h2>
            {result?.data && (
              <span className="text-ink-muted">
                {result.data.data.length} produk di halaman ini
              </span>
            )}
          </div>
          {error ? (
            <CatalogMessage>{error}</CatalogMessage>
          ) : result?.data?.data.length ? (
            <ProductGrid products={result.data.data} />
          ) : (
            <CatalogMessage>
              Tidak ada produk yang cocok. Coba kata pencarian lain atau{" "}
              <Link className="underline" href="/catalog">
                hapus filter
              </Link>
              .
            </CatalogMessage>
          )}
          {!error && result?.data?.meta.next_cursor && (
            <nav
              aria-label="Halaman katalog"
              className="flex justify-center py-6"
            >
              <Link
                className="inline-flex min-h-touch items-center gap-6 rounded-badge border border-control-border px-6 py-3 font-medium"
                href={catalogHref(params, {
                  cursor: result.data.meta.next_cursor,
                })}
              >
                Muat berikutnya <span aria-hidden="true">→</span>
              </Link>
            </nav>
          )}
        </section>
      </div>
    </div>
  );
}
