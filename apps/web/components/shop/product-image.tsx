"use client";

import Image from "next/image";
import { useState } from "react";

export function ProductImage({
  src,
  alt,
  priority = false,
}: {
  src?: string | null;
  alt: string;
  priority?: boolean;
}) {
  const [failed, setFailed] = useState(false);
  return (
    <div className="relative aspect-square overflow-hidden rounded-card bg-surface-muted">
      {src && !failed ? (
        <Image
          src={src}
          alt={alt}
          fill
          sizes="(max-width: 640px) 90vw, (max-width: 1024px) 45vw, 30vw"
          className="object-contain p-6"
          unoptimized
          priority={priority}
          onError={() => setFailed(true)}
        />
      ) : (
        <div
          role="img"
          aria-label={alt}
          className="flex h-full flex-col items-center justify-center gap-3 p-6 text-center text-ink-muted"
        >
          <span aria-hidden="true" className="text-5xl text-brand-text">
            ✳
          </span>
          <span className="text-xs uppercase tracking-widest">
            Gambar belum tersedia
          </span>
        </div>
      )}
    </div>
  );
}
