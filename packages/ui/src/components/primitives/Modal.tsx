"use client";

import { useEffect, useId, useRef } from "react";
import type { ReactNode, RefObject } from "react";
import { Button } from "./Button";

const SIZE = { sm: "max-w-sm", md: "max-w-lg", lg: "max-w-2xl" };

export interface ModalProps {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  size?: keyof typeof SIZE;
  initialFocusRef?: RefObject<HTMLElement | null>;
  footer?: ReactNode;
  children?: ReactNode;
}

export function Modal({ open, onClose, title, description, size = "md", initialFocusRef, footer, children }: ModalProps) {
  const id = useId();
  const ref = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const dialog = ref.current;
    if (!open || !dialog) return;

    const trigger = document.activeElement;
    dialog.showModal();
    const initialFocus = initialFocusRef?.current;
    if (initialFocus && dialog.contains(initialFocus)) initialFocus.focus();

    return () => {
      if (dialog.open) dialog.close();
      if (document.activeElement === document.body && trigger instanceof HTMLElement && trigger.isConnected) {
        trigger.focus();
      }
    };
  }, [open, initialFocusRef]);

  return (
    <dialog
      ref={ref}
      aria-modal="true"
      aria-labelledby={`${id}-title`}
      aria-describedby={description ? `${id}-description` : undefined}
      onCancel={(event) => {
        event.preventDefault();
        onClose();
      }}
      onClose={() => { if (open && !ref.current?.open) onClose(); }}
      className={`m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] overflow-y-auto rounded-card bg-surface text-ink shadow-card backdrop:bg-overlay ${SIZE[size]}`}
    >
      <div className="grid gap-4 p-6">
        <div className="flex items-start justify-between gap-4">
          <h2 id={`${id}-title`} className="self-center text-xl font-semibold">{title}</h2>
          <Button variant="ghost" onClick={onClose}>Tutup</Button>
        </div>
        {description && <p id={`${id}-description`} className="text-ink-muted">{description}</p>}
        {children}
        {footer && <div>{footer}</div>}
      </div>
    </dialog>
  );
}
