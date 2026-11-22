"use client";

import { useId, useState } from "react";
import Link from "next/link";
import { Button } from "@elzara/ui";
import { addCartItem } from "@/lib/shop-api";

export function AddToCart({ variantId, disabled = false, noticeId, hint = "Harga dan stok dikonfirmasi kembali saat checkout." }: { variantId?: string; disabled?: boolean; noticeId?: string; hint?: string }) {
  const id = useId();
  const [adding, setAdding] = useState(false);
  const [notice, setNotice] = useState("");
  const [added, setAdded] = useState(false);
  const describedBy = noticeId ?? id;
  return <div className="space-y-3">
    <Button disabled={adding || disabled || !variantId} block aria-describedby={describedBy} onClick={async () => {
      if (!variantId || disabled) return;
      setAdding(true); setNotice(""); setAdded(false);
      try { await addCartItem(variantId); setNotice("Ditambahkan ke keranjang."); setAdded(true); }
      catch (e) { setNotice(e instanceof Error ? e.message : "Gagal menambahkan produk."); }
      finally { setAdding(false); }
    }}>Tambah ke keranjang</Button>
    <p id={describedBy} role="status" className="text-xs leading-relaxed text-ink-muted">
      {notice || hint}{added && <> <Link href="/cart" className="underline">Lihat keranjang</Link></>}
    </p>
  </div>;
}
