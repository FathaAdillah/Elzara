import Link from "next/link";
import { brand } from "@/config/brand";

export function SiteHeader() {
  return (
    <header className="border-b border-divider bg-surface">
      <div className="mx-auto flex min-h-20 max-w-content flex-wrap items-center justify-between gap-4 px-6 py-3">
        <Link
          href="/"
          className="inline-flex min-h-touch items-center text-2xl font-semibold tracking-tight"
          aria-label={`${brand.name} — beranda`}
        >
          {brand.name}
          <span className="text-brand-text" aria-hidden="true">
            {" "}
            ✳
          </span>
        </Link>
        <nav
          aria-label="Navigasi utama"
          className="flex items-center gap-6 text-sm font-medium"
        >
          <Link
            className="inline-flex min-h-touch items-center"
            href="/catalog"
          >
            Katalog
          </Link>
          <Link
            className="inline-flex min-h-touch items-center"
            href="/catalog?kind=pre_order"
          >
            Pre-order
          </Link>
          <Link className="inline-flex min-h-touch items-center" href="/cart">
            Keranjang
          </Link>
          <Link
            className="inline-flex min-h-touch items-center"
            href="/account"
          >
            Akun{" "}
            <span aria-hidden="true" className="ml-2">
              ↗
            </span>
          </Link>
        </nav>
      </div>
    </header>
  );
}

export function SiteFooter() {
  return (
    <footer className="mt-12 border-t border-divider bg-surface-muted">
      <div className="mx-auto flex max-w-content flex-wrap items-center justify-between gap-4 px-6 py-8 text-sm">
        <p className="font-semibold">{brand.name}</p>
        <p className="text-ink-muted">
          Harga dan ketersediaan dikonfirmasi kembali saat checkout.
        </p>
        <Link
          href="/catalog"
          className="inline-flex min-h-touch items-center font-medium"
        >
          Jelajahi katalog ↗
        </Link>
      </div>
    </footer>
  );
}
