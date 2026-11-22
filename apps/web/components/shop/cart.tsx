"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { Button } from "@elzara/ui";
import { getCart, updateCartItem, removeCartItem } from "@/lib/commerce-api";
import { modeLabel, rupiah, validQuantity, type Cart } from "@/lib/commerce";
export function CartPanel() {
  const [cart, setCart] = useState<Cart>();
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  useEffect(() => { getCart().then(setCart).catch((e: Error) => setError(e.message)); }, []);
  async function change(id: string, quantity?: number) {
    setBusy(true); setError("");
    try { setCart(await (quantity === undefined ? removeCartItem(id) : updateCartItem(id, quantity))); }
    catch (e) { setError(e instanceof Error ? e.message : "Tidak dapat memperbarui keranjang."); }
    finally { setBusy(false); }
  }
  return <div className="space-y-8">
    {error && <p role="alert" className="rounded-card border border-divider p-4">{error}</p>}
    {!cart ? <p role="status">Memuat keranjang…</p> : <>
      {cart.groups.length === 0 && <p>Keranjang kosong atau telah kedaluwarsa. <Link href="/catalog" className="underline">Jelajahi produk</Link></p>}
      {cart.groups.map((group, i) => <section key={i} className="space-y-4 rounded-card border border-divider p-5">
        <h2 className="font-semibold">{modeLabel[group.mode]} · ETA: {group.eta ?? "Belum tersedia"}</h2>
        {group.items.map((item) => <article key={item.id} className="flex flex-wrap items-center justify-between gap-4 border-t border-divider pt-4">
          <div className="space-y-1"><h3 className="font-medium">{item.name}</h3><p>{rupiah(item.unit_price.amount_minor)}</p>
            {item.variant_label && <p className="text-sm">Varian: {item.variant_label}</p>}
            {item.sku && <p className="text-xs text-ink-muted">SKU: {item.sku}</p>}
            {item.price_changed && <p className="text-sm text-ink-muted">Harga berubah sejak ditambahkan. Harga terbaru digunakan.</p>}
            {item.unavailable && <p role="status" className="text-sm text-ink-muted">Tidak tersedia atau stok tidak cukup. Kurangi jumlah atau hapus.</p>}
          </div>
          <label className="flex items-center gap-2 text-sm">Jumlah
            <input key={item.id + ":" + item.quantity} type="number" min={1} max={99} defaultValue={item.quantity} disabled={busy}
              onBlur={(e) => { const n = validQuantity(e.target.value); if (n === undefined) { e.target.value = String(item.quantity); setError("Jumlah harus 1–99."); } else if (n !== item.quantity) void change(item.id, n); }}
              className="min-h-touch w-20 rounded-control border border-control-border bg-canvas p-2" />
          </label>
          <p>{rupiah(item.line_total.amount_minor)}</p><Button variant="ghost" disabled={busy} onClick={() => void change(item.id)}>Hapus</Button>
        </article>)}
        <p className="text-right font-medium">Subtotal grup: {rupiah(group.subtotal.amount_minor)}</p>
      </section>)}
      <p className="text-xl font-semibold">Subtotal: {rupiah(cart.subtotal.amount_minor)}</p>
      {cart.expires_at && <p className="text-sm text-ink-muted">Berlaku hingga {new Date(cart.expires_at).toLocaleString("id-ID")}. Diperpanjang saat keranjang diubah.</p>}
      {cart.groups.length > 0 && <Link href="/checkout" className="inline-flex min-h-touch items-center rounded-control bg-brand-text px-5 text-white">Lanjut ke checkout</Link>}
    </>}
  </div>;
}
