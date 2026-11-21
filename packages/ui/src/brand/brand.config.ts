// packages/ui/src/brand/brand.config.ts - bentuk + default statis brand, dikonsumsi DUA app (Doc 04 §3).
// Teks/legal/kontak yang dinamis tetap milik Laravel API (/api/v1 brand+nav+content); paket ini hanya
// menjamin tipenya sama di kedua app, sehingga override tidak pernah diletakkan di salah satu app (Doc 04 §11).

export const brandThemes = ["playful", "clean-premium", "bold-kpop", "brand"] as const;
export type BrandTheme = (typeof brandThemes)[number];

export interface BrandConfig {
  name: string;
  tagline: string;
  supportWhatsapp: string;
  legal: { company: string; address: string; npwp: string; email: string };
  nav: { id: string; label: string; to: string; badge?: string }[];
  links: { terms: string; privacy: string; howToPo: string };
  social: Record<string, string>;
  locale: "id";
  currency: "IDR";
  tz: "Asia/Jakarta";
  theme: BrandTheme;
  seo: { defaultOg: string; siteName: string };
}

export const brand: BrandConfig = {
  name: "Elzara",
  tagline: "",
  supportWhatsapp: "",
  legal: { company: "", address: "", npwp: "", email: "" },
  nav: [],
  links: { terms: "", privacy: "", howToPo: "" },
  social: {},
  locale: "id",
  currency: "IDR",
  tz: "Asia/Jakarta",
  theme: "brand",
  seo: { defaultOg: "", siteName: "Elzara" },
};

export function resolveBrandTheme(value: string | undefined, fallback: BrandTheme | "admin" = brand.theme) {
  if (!value) return fallback;
  const theme = brandThemes.find((theme) => theme === value);
  if (theme) return theme;
  if (value === "admin" && fallback === "admin") return value;
  throw new Error(`BRAND_THEME tidak dikenal: ${value}. Pilih ${[...brandThemes, ...(fallback === "admin" ? ["admin"] : [])].join(", ")}.`);
}
