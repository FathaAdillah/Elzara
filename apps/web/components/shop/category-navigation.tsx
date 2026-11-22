import Link from "next/link";
import Image from "next/image";
import { storefront } from "@/config/storefront";
import type { Category } from "@/lib/catalog";
import { ShopIcon, type ShopIconName } from "./shop-icon";

const icons: ShopIconName[] = [
  "heart",
  "star",
  "bag",
  "box",
  "grid",
  "headphones",
  "heart",
  "star",
];

export function CategoryTiles({ categories }: { categories: Category[] }) {
  return (
    <nav
      className="store-category-tiles"
      aria-label="Belanja berdasarkan kategori"
    >
      {categories.map((category, index) => (
        <Link
          key={category.slug}
          href={`/catalog?category=${encodeURIComponent(category.slug)}`}
          className={`store-category-tile store-category-tone-${index % 4}`}
        >
          <span className="store-category-art">
            {storefront.categoryImages[category.slug] ? (
              <Image
                src={storefront.categoryImages[category.slug]!}
                alt=""
                width={54}
                height={54}
              />
            ) : (
              <ShopIcon name={icons[index % icons.length] ?? "star"} />
            )}
          </span>
          <span>{category.name}</span>
          <small>Jelajahi koleksi</small>
        </Link>
      ))}
    </nav>
  );
}

export function ShoppingModes() {
  const modes: { href: string; label: string; icon: ShopIconName }[] = [
    { href: "/catalog", label: "Semua produk", icon: "grid" },
    { href: "/catalog?kind=rts", label: "Ready stock", icon: "box" },
    { href: "/catalog?kind=pre_order", label: "Pre-order", icon: "star" },
    { href: "/catalog?kind=request_order", label: "Request", icon: "search" },
  ];
  return (
    <nav className="store-shopping-modes" aria-label="Belanja menurut mode">
      {modes.map((mode) => (
        <Link key={mode.href} href={mode.href}>
          <ShopIcon name={mode.icon} />
          {mode.label}
        </Link>
      ))}
    </nav>
  );
}
