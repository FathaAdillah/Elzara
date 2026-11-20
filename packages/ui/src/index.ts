// Rumah design token tiga lapis + primitif React yang dipakai kedua app (Doc 04 §1).
// Logika uang TIDAK tinggal di sini - format IDR milik app, dan nilainya berasal dari API
// (Doc 01 §3.1 melarangnya di packages/ui). Session/data privat juga tidak boleh dibagikan lewat paket ini.

import { Button } from "./components/primitives/Button";

export { Button };
export type { ButtonProps, ButtonSize, ButtonVariant } from "./components/primitives/Button";

/** Nama variabel Lapis 2. Nilai konkretnya milik browser; komponen dan chart menyebut nama ini. */
export const semanticTokens = [
  "surface",
  "surface-muted",
  "ink",
  "ink-muted",
  "brand",
  "brand-ink",
  "accent",
  "success",
  "warning",
  "danger",
  "info",
  "po",
  "ready",
  "quote",
  "focus-ring",
  "overlay",
  "divider",
] as const;

export type SemanticToken = (typeof semanticTokens)[number];

/** Mirror untuk chart/canvas: next/image dan ApexCharts tidak selalu bisa membaca CSS var saat SSR (Doc 04 §2.1). */
export const semanticVar = (token: SemanticToken): string => `var(--color-${token})`;

export { chartPalette } from "./formatting/tokens";

export { brand } from "./brand/brand.config";
export type { BrandConfig } from "./brand/brand.config";

/** Lapis 3 - slot yang dibaca komponen di luar CSS (mis. lebar drawer untuk kalkulasi layout server-side). */
export const slots = {
  cartDrawerWidth: "var(--cart-drawer-width)",
  buttonHeight: "var(--button-height)",
  touchTargetMin: "var(--touch-target-min)",
  contentMax: "var(--content-max)",
} as const;
