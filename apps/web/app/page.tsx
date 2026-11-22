import Link from "next/link";
import { brand } from "@/config/brand";
import { getCategories, getProducts } from "@/lib/catalog-api";
import { CatalogMessage, ProductGrid } from "@/components/shop/products";
import { StorefrontBanner } from "@/components/shop/storefront-banner";
import {
  CategoryTiles,
  ShoppingModes,
} from "@/components/shop/category-navigation";
import { ShopIcon } from "@/components/shop/shop-icon";

export default async function HomePage() {
  const [categories, products] = await Promise.all([
    getCategories(),
    getProducts({ limit: 8 }),
  ]);
  return (
    <main id="konten" tabIndex={-1} className="store-home store-container">
      <h1 className="sr-only">{brand.name} — pilihan untukmu</h1>
      <StorefrontBanner />
      <section className="store-services" aria-label="Layanan belanja">
        {[
          {
            icon: "truck" as const,
            title: "Pilihan pengiriman",
            description: "Sesuaikan dengan kebutuhanmu",
          },
          {
            icon: "box" as const,
            title: "Belanja lebih mudah",
            description: "Ready stock hingga pre-order",
          },
          {
            icon: "headphones" as const,
            title: "Selalu terhubung",
            description: "Pantau pesanan lewat akunmu",
          },
        ].map((service) => (
          <article key={service.title}>
            <ShopIcon name={service.icon} />
            <div>
              <h2>{service.title}</h2>
              <p>{service.description}</p>
            </div>
          </article>
        ))}
      </section>
      <section className="store-section" aria-labelledby="categories-title">
        <div className="store-section-heading">
          <h2 id="categories-title">
            <ShopIcon name="heart" />
            Belanja berdasarkan kategori
          </h2>
          <Link href="/catalog">
            Lihat semua
            <ShopIcon name="arrow" />
          </Link>
        </div>
        {categories.length ? (
          <CategoryTiles categories={categories.slice(0, 8)} />
        ) : (
          <ShoppingModes />
        )}
      </section>
      <section className="store-section" aria-labelledby="latest-title">
        <div className="store-section-heading">
          <h2 id="latest-title">
            <ShopIcon name="star" />
            Produk terbaru
          </h2>
          <Link href="/catalog">
            Lihat semua
            <ShopIcon name="arrow" />
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
