import Image from "next/image";
import Link from "next/link";
import { brand } from "@/config/brand";
import { storefront } from "@/config/storefront";
import { getCategories } from "@/lib/catalog-api";
import { ShopIcon } from "./shop-icon";
import { SiteNavigation } from "./site-navigation";
import { CategoryMenu } from "./category-menu";

function BrandMark() {
  return (
    <Link
      href="/"
      className="store-brand"
      aria-label={`${brand.name} — beranda`}
    >
      {storefront.logo ? (
        <Image
          src={storefront.logo}
          width={200}
          height={100}
          alt={brand.name}
        />
      ) : (
        <>
          <span className="store-brand-symbol">
            <ShopIcon name="bag" />
            <span aria-hidden="true">✦</span>
          </span>
          <span>
            {brand.name}
            <small>{brand.tagline || "Ready stock · Pre-order"}</small>
          </span>
        </>
      )}
    </Link>
  );
}

export async function SiteHeader() {
  const categories = await getCategories();
  return (
    <header className="store-header">
      <div className="store-header-inner">
        <div className="store-header-top">
          <Link
            href="/catalog"
            className="store-mobile-menu"
            aria-label="Buka katalog"
          >
            <ShopIcon name="menu" />
          </Link>
          <BrandMark />
          <form
            action="/catalog"
            method="get"
            role="search"
            className="store-search"
          >
            <ShopIcon name="search" />
            <label className="sr-only" htmlFor="store-search">
              Cari produk atau brand
            </label>
            <input
              id="store-search"
              name="q"
              type="search"
              maxLength={200}
              placeholder="Cari produk atau brand…"
            />
            <button type="submit" aria-label="Cari produk">
              <ShopIcon name="search" />
            </button>
          </form>
          <SiteNavigation />
        </div>
        <nav className="store-category-bar" aria-label="Kategori produk">
          <CategoryMenu>
              <Link href="/catalog">
                Semua produk
                <ShopIcon name="arrow" />
              </Link>
              {categories.map((category) => (
                <Link
                  key={category.slug}
                  href={`/catalog?category=${encodeURIComponent(category.slug)}`}
                >
                  {category.name}
                  <ShopIcon name="arrow" />
                </Link>
              ))}
          </CategoryMenu>
          <div className="store-category-shortcuts">
            {categories.length ? (
              categories.slice(0, 6).map((category, index) => (
                <Link
                  key={category.slug}
                  href={`/catalog?category=${encodeURIComponent(category.slug)}`}
                >
                  <span
                    className={`store-category-mini store-category-tone-${index % 4}`}
                  >
                    <ShopIcon name="star" />
                  </span>
                  {category.name}
                </Link>
              ))
            ) : (
              <>
                <Link href="/catalog?kind=rts">Ready stock</Link>
                <Link href="/catalog?kind=pre_order">Pre-order</Link>
                <Link href="/catalog?kind=request_order">Request</Link>
              </>
            )}
          </div>
        </nav>
      </div>
      <SiteNavigation mobile />
    </header>
  );
}

export function SiteFooter() {
  const policies = [
    { label: "Syarat & ketentuan", href: brand.links.terms },
    { label: "Kebijakan privasi", href: brand.links.privacy },
    { label: "Cara pre-order", href: brand.links.howToPo },
  ].filter((link) => link.href);
  return (
    <footer className="store-footer">
      <div className="store-container">
        <div className="store-footer-grid">
          <section
            className="store-footer-about"
            aria-label={`Tentang ${brand.name}`}
          >
            <BrandMark />
            <p>
              {brand.tagline ||
                "Temukan produk pilihan melalui ready stock, pre-order, dan request dalam satu tempat."}
            </p>
            {brand.legal.email && (
              <a href={`mailto:${brand.legal.email}`}>
                <ShopIcon name="mail" />
                {brand.legal.email}
              </a>
            )}
            {brand.supportWhatsapp && (
              <a href={brand.supportWhatsapp}>
                WhatsApp
                <ShopIcon name="arrow" />
              </a>
            )}
          </section>
          <section>
            <h2>Jelajahi</h2>
            <Link href="/catalog">Semua produk</Link>
            <Link href="/catalog?kind=rts">Ready stock</Link>
            <Link href="/catalog?kind=pre_order">Pre-order</Link>
            <Link href="/catalog?kind=request_order">Request produk</Link>
          </section>
          <section>
            <h2>Belanja</h2>
            <Link href="/account">Akun saya</Link>
            <Link href="/cart">Keranjang belanja</Link>
            {policies.map((link) => (
              <a key={link.label} href={link.href}>
                {link.label}
              </a>
            ))}
          </section>
          <section>
            <h2>Belanja dengan tenang</h2>
            <p>Harga dan ketersediaan dikonfirmasi kembali saat checkout.</p>
            {Object.entries(brand.social).map(
              ([name, href]) =>
                href && (
                  <a key={name} href={href}>
                    {name}
                    <ShopIcon name="arrow" />
                  </a>
                ),
            )}
          </section>
        </div>
        <div className="store-footer-bottom">
          <span>
            © {new Date().getFullYear()} {brand.legal.company || brand.name}
          </span>
          <span>Dikurasi dengan sepenuh hati.</span>
        </div>
      </div>
    </footer>
  );
}
