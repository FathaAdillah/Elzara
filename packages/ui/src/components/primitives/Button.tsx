import type { ButtonHTMLAttributes, ReactNode } from "react";

export type ButtonVariant = "primary" | "secondary" | "ghost" | "danger" | "accent";
export type ButtonSize = "sm" | "md" | "lg";

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
  block?: boolean;
  leadingIcon?: ReactNode;
  trailingIcon?: ReactNode;
}

// Komponen hanya menyebut Lapis 2 (warna) dan Lapis 3 (ukuran) - tidak ada nilai konkret di berkas ini.
const VARIANT: Record<ButtonVariant, string> = {
  primary: "bg-brand text-surface",
  secondary: "bg-brand-ink text-ink",
  ghost: "bg-transparent text-ink",
  danger: "bg-danger text-surface",
  accent: "bg-accent text-ink",
};

const SIZE: Record<ButtonSize, string> = {
  sm: "h-button-sm px-3 text-sm",
  md: "h-button px-4",
  lg: "h-button-lg px-6 text-lg",
};

const FOCUS =
  "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring";

export function Button({
  variant = "primary",
  size = "md",
  loading = false,
  block = false,
  leadingIcon,
  trailingIcon,
  className = "",
  disabled,
  children,
  ...rest
}: ButtonProps) {
  return (
    <button
      {...rest}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={[
        VARIANT[variant],
        SIZE[size],
        // min-h-touch menjaga target >=44px walau varian sm lebih pendek (Doc 04 §5.2)
        "inline-flex min-h-touch items-center justify-center gap-2 rounded-badge font-medium",
        "transition-base hover:brightness-95 motion-reduce:transition-none disabled:opacity-50",
        FOCUS,
        block ? "w-full" : "",
        className,
      ]
        .filter(Boolean)
        .join(" ")}
    >
      {leadingIcon}
      {children}
      {trailingIcon}
    </button>
  );
}
