"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import type { components } from "@elzara/api-client";
import { AddToCart } from "./add-to-cart";
import { formatIdr } from "@/lib/money";
import { canEnroll, countdownLabel, remainingSeconds } from "@/lib/preorder";

export function PreorderItems({ batch }: { batch: components["schemas"]["PreorderBatchDetail"] }) {
  const [elapsed, setElapsed] = useState(0);
  useEffect(() => {
    const start = performance.now();
    const timer = window.setInterval(() => setElapsed(performance.now() - start), 1000);
    return () => window.clearInterval(timer);
  }, [batch.now]);
  const seconds = remainingSeconds(batch.now, batch.closes_at, elapsed);
  return <>
    <p className="rounded-card bg-brand-ink p-4">Tutup dalam: <span data-testid="batch-countdown">{countdownLabel(seconds)}</span></p>
    <p className="text-xs text-ink-muted">Mengikuti waktu server: <time dateTime={batch.now}>{batch.now}</time>. Kuota dikonfirmasi kembali saat pemesanan.</p>
    <div className="grid gap-6 sm:grid-cols-2">
      {batch.items.map((item) => <article key={item.id} className="space-y-4 rounded-card border border-divider p-5">
        <h2 className="text-xl font-semibold"><Link href={`/catalog/${item.product_slug}`} className="underline">{item.product_name}</Link></h2>
        <p>{item.variant_name}</p>
        <p className="font-semibold">{formatIdr(item.price.amount_minor)}</p>
        <p>Kuota: {item.remaining} dari {item.quota} tersisa</p>
        <p>Batas per akun: {item.max_per_account ?? "Tanpa batas"}</p>
        <AddToCart variantId={item.variant_id} disabled={seconds === 0 || !canEnroll(batch.status, item, batch.now, elapsed)} />
      </article>)}
    </div>
    {batch.items.length === 0 && <p>Belum ada produk dalam batch ini.</p>}
  </>;
}
