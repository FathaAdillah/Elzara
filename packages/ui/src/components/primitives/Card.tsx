import type { HTMLAttributes, ReactNode } from "react";

const PADDING = { none: "", sm: "p-3", md: "p-4", lg: "p-6" };

export interface CardProps extends HTMLAttributes<HTMLElement> {
  as?: "div" | "article" | "section";
  padding?: keyof typeof PADDING;
  header?: ReactNode;
  footer?: ReactNode;
}

export function Card({
  as: Element = "div",
  padding = "md",
  header,
  footer,
  children,
  className = "",
  ...props
}: CardProps) {
  return (
    <Element {...props} className={`grid gap-3 rounded-card bg-surface text-ink shadow-card ${PADDING[padding]} ${className}`}>
      {header && <div>{header}</div>}
      {children}
      {footer && <div>{footer}</div>}
    </Element>
  );
}
