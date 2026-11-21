import { useId } from "react";
import type { ComponentPropsWithRef, ReactNode } from "react";

export interface InputProps extends ComponentPropsWithRef<"input"> {
  label: string;
  hint?: string;
  error?: string;
  addonBefore?: ReactNode;
  addonAfter?: ReactNode;
}

export function Input({
  label,
  hint,
  error,
  addonBefore,
  addonAfter,
  id,
  className = "",
  "aria-describedby": describedBy,
  "aria-invalid": invalid,
  ...props
}: InputProps) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  const description = [describedBy, hint && `${inputId}-hint`, error && `${inputId}-error`]
    .filter(Boolean)
    .join(" ");

  return (
    <div className="grid gap-2">
      <label className="font-medium text-ink" htmlFor={inputId}>{label}</label>
      <div className="flex items-center gap-2">
        {addonBefore}
        <input
          {...props}
          id={inputId}
          aria-describedby={description || undefined}
          aria-invalid={error ? true : invalid}
          className={`min-h-touch w-full min-w-0 rounded-card border bg-surface px-3 text-ink placeholder:text-ink-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring disabled:opacity-50 ${error ? "border-danger" : "border-control-border"} ${className}`}
        />
        {addonAfter}
      </div>
      {hint && <p id={`${inputId}-hint`} className="text-sm text-ink-muted">{hint}</p>}
      {error && <p id={`${inputId}-error`} className="text-sm font-medium text-ink">{error}</p>}
    </div>
  );
}
