// packages/ui/src/formatting/tokens.ts - mirror TypeScript murni dari token, bebas DOM dan SSR-safe (Doc 04 §2.1).
// Pemanggilnya (chart/canvas) menyebut NAMA Lapis 2, bukan hex: nilainya tetap satu sumber di CSS.

export const chartPalette = ["brand", "accent", "po", "ready", "warning", "danger"] as const;

export type ChartToken = (typeof chartPalette)[number];
