import type { HTMLAttributes, ReactNode } from "react";

const TONE = {
  po: "bg-po text-on-status",
  ready: "bg-ready text-on-status",
  quote: "bg-quote text-on-status",
  success: "bg-success text-on-status",
  warning: "bg-warning text-on-status",
  danger: "bg-danger text-on-status",
  info: "bg-info text-on-status",
  neutral: "bg-surface-muted text-ink",
};
const SHAPE = { pill: "rounded-badge", block: "rounded-card", uppercase: "rounded-badge uppercase" };

export type BadgeTone = keyof typeof TONE;

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  tone?: BadgeTone;
  shape?: keyof typeof SHAPE;
  icon?: ReactNode;
}

export function Badge({ tone = "neutral", shape = "pill", icon, children, className = "", ...props }: BadgeProps) {
  return (
    <span {...props} className={`inline-flex items-center gap-1 px-2 py-1 text-xs font-medium ${TONE[tone]} ${SHAPE[shape]} ${className}`}>
      {icon && <span aria-hidden="true">{icon}</span>}
      {children}
    </span>
  );
}
