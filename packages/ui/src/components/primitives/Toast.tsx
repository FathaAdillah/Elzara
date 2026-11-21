"use client";

import { useEffect, useState } from "react";
import { Button } from "./Button";

const TONE = { success: "border-s-success", danger: "border-s-danger", info: "border-s-info" };
const ALIGN = { br: "items-end", bl: "items-start" };

export interface ToastProps {
  id: string;
  tone: keyof typeof TONE;
  title: string;
  body?: string;
  durationMs?: number;
  onDismiss: (id: string) => void;
}

export interface ToastViewportProps {
  toasts: readonly ToastProps[];
  align?: keyof typeof ALIGN;
}

function Toast({ id, title, body, tone, durationMs, onDismiss }: ToastProps) {
  const [hovered, setHovered] = useState(false);
  const [focused, setFocused] = useState(false);

  useEffect(() => {
    if (!durationMs || durationMs < 0 || hovered || focused) return;
    const timer = window.setTimeout(() => onDismiss(id), durationMs);
    return () => window.clearTimeout(timer);
  }, [durationMs, focused, hovered, id, onDismiss]);

  return (
    <li
      onPointerEnter={() => setHovered(true)}
      onPointerLeave={() => setHovered(false)}
      onFocus={() => setFocused(true)}
      onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setFocused(false); }}
      className={`pointer-events-auto flex w-full max-w-sm items-center justify-between gap-3 rounded-card border-s-4 bg-surface p-4 text-ink shadow-card ${TONE[tone]}`}
    >
      <div className="min-w-0 wrap-break-word">
        <p className="font-semibold">{title}</p>
        {body && <p className="text-sm text-ink-muted">{body}</p>}
      </div>
      <Button variant="ghost" size="sm" onClick={() => onDismiss(id)} aria-label={`Tutup notifikasi: ${title}`}>
        Tutup
      </Button>
    </li>
  );
}

export function ToastViewport({ toasts, align = "br" }: ToastViewportProps) {
  return (
    <>
      <div className="sr-only" role="status" aria-live="polite" aria-atomic="false" aria-relevant="additions text">
        {toasts.map(({ id, title, body }) => <p key={id}>{body ? `${title}. ${body}` : title}</p>)}
      </div>
      {toasts.length > 0 && (
        <ul aria-label="Notifikasi" className={`pointer-events-none fixed inset-x-4 bottom-4 z-toast flex max-h-[60dvh] flex-col gap-2 overflow-auto p-1 ${ALIGN[align]}`}>
          {toasts.map((toast) => <Toast key={toast.id} {...toast} />)}
        </ul>
      )}
    </>
  );
}
