import type { Metadata, Viewport } from "next";
import { brand, resolveBrandTheme } from "@/config/brand";
import { SkipLink } from "./skip-link";
import "./globals.css";

export const dynamic = "force-dynamic";

/**
 * Metadata publik diambil dari config brand, bukan string literal di komponen
 * (Doc 04 §3, §5: nama toko / tagline / legal tidak boleh di-hardcode).
 * OG image & logo adalah slot branding yang diisi aset orisinal milik klien
 * di `public/brand/` (Doc 04 §5, Doc 01 §15).
 */
export const metadata: Metadata = {
  title: {
    default: brand.tagline ? `${brand.name} — ${brand.tagline}` : brand.name,
    template: `%s · ${brand.name}`,
  },
  description: brand.tagline || undefined,
  metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost"),
  openGraph: { siteName: brand.seo.siteName, images: [brand.seo.defaultOg] },
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang={brand.locale} data-brand-theme={resolveBrandTheme(process.env.NEXT_PUBLIC_BRAND_THEME)}>
      {/* Skip-link "ke konten" adalah bagian gerbang aksesibilitas tiap sprint UI
          (Doc 04 §5.2) — bukan hiasan, dan tidak memakai warna lokal. */}
      <body className="bg-surface text-ink antialiased">
        <SkipLink />
        {children}
      </body>
    </html>
  );
}
