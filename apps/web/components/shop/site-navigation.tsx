"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { ShopIcon, type ShopIconName } from "./shop-icon";

export function SiteNavigation({ mobile = false }: { mobile?: boolean }) {
  const pathname = usePathname();
  const navigation: { href: string; label: string; icon: ShopIconName }[] = [
    { href: "/", label: "Beranda", icon: "home" },
    {
      href: "/catalog",
      label: mobile ? "Kategori" : "Katalog",
      icon: mobile ? "grid" : "bag",
    },
    ...(!mobile
      ? [
          {
            href: "/catalog?kind=pre_order",
            label: "Pre-order",
            icon: "star" as const,
          },
        ]
      : []),
    { href: "/cart", label: "Keranjang", icon: "cart" },
    { href: "/account", label: "Akun", icon: "user" },
  ];
  return (
    <nav
      className={mobile ? "store-mobile-nav" : "store-main-nav"}
      aria-label={mobile ? "Navigasi seluler" : "Navigasi utama"}
    >
      {navigation.map((item) => (
        <Link
          key={item.href}
          href={item.href}
          aria-label={item.label}
          aria-current={
            item.href === "/"
              ? pathname === "/"
                ? "page"
                : undefined
              : !item.href.includes("?") &&
                  (pathname === item.href ||
                    pathname.startsWith(`${item.href}/`))
                ? "page"
                : undefined
          }
        >
          <ShopIcon name={item.icon} />
          <span>{item.label}</span>
        </Link>
      ))}
    </nav>
  );
}
