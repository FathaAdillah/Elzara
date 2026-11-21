import type { HTMLAttributes } from "react";

const BASE = { 1: "grid-cols-1", 2: "grid-cols-2" };
const SM = { 2: "sm:grid-cols-2", 3: "sm:grid-cols-3" };
const MD = { 3: "md:grid-cols-3", 4: "md:grid-cols-4" };
const LG = { 4: "lg:grid-cols-4", 6: "lg:grid-cols-6" };
const GAP = { sm: "gap-2", md: "gap-gutter", lg: "gap-gutter-lg" };
const ALIGN = { start: "items-start", stretch: "items-stretch" };

export interface GridProps extends HTMLAttributes<HTMLDivElement> {
  columns?: { base?: keyof typeof BASE; sm?: keyof typeof SM; md?: keyof typeof MD; lg?: keyof typeof LG };
  gap?: keyof typeof GAP;
  alignItems?: keyof typeof ALIGN;
}

export function Grid({ columns = {}, gap = "md", alignItems = "stretch", className = "", ...props }: GridProps) {
  const { base = 1, sm, md, lg } = columns;
  return (
    <div
      {...props}
      className={["grid", BASE[base], sm && SM[sm], md && MD[md], lg && LG[lg], GAP[gap], ALIGN[alignItems], className]
        .filter(Boolean).join(" ")}
    />
  );
}
