// Rumah design token tiga lapis + primitif React storefront (Doc 04 §1).
// Logika uang TIDAK tinggal di sini - format IDR milik app, dan nilainya berasal dari API
// (Doc 01 §3.1 melarangnya di packages/ui). Session/data privat juga tidak boleh dibagikan lewat paket ini.

export { Button } from "./components/primitives/Button";
export type { ButtonProps, ButtonSize, ButtonVariant } from "./components/primitives/Button";
export { Input } from "./components/primitives/Input";
export type { InputProps } from "./components/primitives/Input";
export { Card } from "./components/primitives/Card";
export type { CardProps } from "./components/primitives/Card";
export { Badge } from "./components/primitives/Badge";
export type { BadgeProps, BadgeTone } from "./components/primitives/Badge";
export { Modal } from "./components/primitives/Modal";
export type { ModalProps } from "./components/primitives/Modal";
export { ToastViewport } from "./components/primitives/Toast";
export type { ToastProps, ToastViewportProps } from "./components/primitives/Toast";
export { Grid } from "./components/primitives/Grid";
export type { GridProps } from "./components/primitives/Grid";

/** Nama variabel Lapis 2. Nilai konkretnya milik browser; komponen dan chart menyebut nama ini. */
export const semanticTokens = [
  "surface",
  "surface-muted",
  "ink",
  "ink-muted",
  "brand",
  "brand-ink",
  "brand-text",
  "on-brand",
  "on-status",
  "accent",
  "on-accent",
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
  "control-border",
] as const;

export type SemanticToken = (typeof semanticTokens)[number];

/** Mirror untuk chart/canvas: next/image dan ApexCharts tidak selalu bisa membaca CSS var saat SSR (Doc 04 §2.1). */
export const semanticVar = (token: SemanticToken): string => `var(--color-${token})`;

export { chartPalette } from "./formatting/tokens";

export { brand, brandThemes, resolveBrandTheme } from "./brand/brand.config";
export type { BrandConfig, BrandTheme } from "./brand/brand.config";

/** Lapis 3 - slot yang dibaca komponen di luar CSS (mis. lebar drawer untuk kalkulasi layout server-side). */
export const slots = {
  cartDrawerWidth: "var(--cart-drawer-width)",
  buttonHeight: "var(--button-height)",
  touchTargetMin: "var(--touch-target-min)",
  contentMax: "var(--content-max)",
} as const;
