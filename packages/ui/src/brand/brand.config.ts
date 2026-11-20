// packages/ui/src/brand/brand.config.ts - bentuk + default statis brand, dikonsumsi DUA app (Doc 04 §3).
// Teks/legal/kontak yang dinamis tetap milik Laravel API (/api/v1 brand+nav+content); paket ini hanya
// menjamin tipenya sama di kedua app, sehingga override tidak pernah diletakkan di salah satu app (Doc 04 §11).

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
  theme: "playful" | "clean-premium" | "bold-kpop";
  seo: { defaultOg: string; siteName: string };
}

export const brand: BrandConfig = {
  name: "",
  tagline: "",
  supportWhatsapp: "",
  legal: { company: "", address: "", npwp: "", email: "" },
  nav: [],
  links: { terms: "", privacy: "", howToPo: "" },
  social: {},
  locale: "id",
  currency: "IDR",
  tz: "Asia/Jakarta",
  // Preset yang diimpor globals.css kedua app hari ini; diganti lewat BRAND_THEME saat build (Doc 04 §4).
  theme: "playful",
  seo: { defaultOg: "", siteName: "" },
};
