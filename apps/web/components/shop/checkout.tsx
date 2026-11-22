"use client";
import { useEffect, useState, type FormEvent } from "react";
import { Button } from "@elzara/ui";
import { getCart, getAddresses, saveAddress, createCheckout, shippingOptions } from "@/lib/commerce-api";
import { CommerceError, modeLabel, rupiah, type Address, type Cart, type Checkout, type Shipping, type AddressWrite } from "@/lib/commerce";
const fields: { name: keyof AddressWrite; label: string; max: number }[] = [
  { name: "recipient", label: "Nama penerima", max: 120 }, { name: "phone", label: "Nomor ponsel Indonesia", max: 20 },
  { name: "province", label: "Provinsi", max: 60 }, { name: "city", label: "Kota / kabupaten", max: 60 },
  { name: "district", label: "Kecamatan", max: 60 }, { name: "postal_code", label: "Kode pos", max: 5 },
  { name: "address_line", label: "Alamat lengkap", max: 1000 },
];
export function CheckoutPanel() {
  const [cart, setCart] = useState<Cart>();
  const [addresses, setAddresses] = useState<Address[]>([]);
  const [selected, setSelected] = useState("");
  const [session, setSession] = useState<Checkout>();
  const [shipping, setShipping] = useState<Shipping>();
  const [choices, setChoices] = useState<Record<number, string>>({});
  const [error, setError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  useEffect(() => { Promise.all([getCart(), getAddresses()]).then(([c, a]) => { setCart(c); setAddresses(a.data); setSelected(a.data.find((v) => v.is_default)?.id ?? a.data[0]?.id ?? ""); }).catch((e: Error) => setError(e.message)); }, []);
  function report(e: unknown) {
    setError(e instanceof Error ? e.message : "Permintaan gagal.");
    if (e instanceof CommerceError) setFieldErrors(Object.fromEntries((e.problem.errors ?? []).map((v) => [v.pointer.slice(1), v.detail])));
  }
  async function addAddress(e: FormEvent<HTMLFormElement>) {
    e.preventDefault(); const form = e.currentTarget; const data = new FormData(form);
    setBusy(true); setError(""); setFieldErrors({});
    try {
      const body = Object.fromEntries(fields.map((f) => [f.name, String(data.get(f.name) ?? "")])) as AddressWrite;
      const a = await saveAddress(body); setAddresses((prev) => [...prev, a]); setSelected(a.id); setSession(undefined); setShipping(undefined); form.reset();
    } catch (e) { report(e); } finally { setBusy(false); }
  }
  async function quote() {
    if (!cart) return; setBusy(true); setError(""); setShipping(undefined);
    try {
      const s = await createCheckout(selected, cart.items_hash); setSession(s);
      const rates = await shippingOptions(s.id); setShipping(rates); setSession(rates.checkout); setChoices({});
    } catch (e) { report(e); setCart(await getCart().catch(() => undefined)); } finally { setBusy(false); }
  }
  return <div className="grid gap-8 lg:grid-cols-2">
    <div className="space-y-6">
      {error && <p role="alert" className="rounded-card border border-divider p-4">{error}</p>}
      <fieldset className="space-y-3"><legend className="mb-3 text-lg font-semibold">Alamat pengiriman</legend>
        {addresses.length === 0 && <p className="text-sm">Tambahkan alamat untuk melanjutkan.</p>}
        {addresses.map((a) => <label key={a.id} className="flex min-h-touch items-start gap-3 rounded-card border border-control-border p-4">
          <input type="radio" name="address" value={a.id} checked={selected === a.id} disabled={busy} onChange={() => { setSelected(a.id); setSession(undefined); setShipping(undefined); }} className="mt-1 accent-brand-text" />
          <span><strong>{a.recipient}</strong> · {a.phone}{a.is_default ? " · Utama" : ""}<br />{a.address_line}, {a.district}, {a.city}, {a.province} {a.postal_code}</span>
        </label>)}
      </fieldset>
      <details className="rounded-card border border-divider p-5" open={addresses.length === 0}><summary className="cursor-pointer font-semibold">Tambah alamat</summary>
        <form onSubmit={(e) => void addAddress(e)} className="mt-4 space-y-4">
          {fields.map((f) => <label key={f.name} className="block space-y-1 text-sm"><span>{f.label}</span>
            <input name={f.name} required maxLength={f.max} type={f.name === "phone" ? "tel" : "text"} aria-invalid={!!fieldErrors[f.name]} aria-describedby={fieldErrors[f.name] ? "error-" + f.name : undefined} className="min-h-touch w-full rounded-control border border-control-border bg-canvas p-3" />
            {fieldErrors[f.name] && <span id={"error-" + f.name} className="block text-sm" role="alert">{fieldErrors[f.name]}</span>}
          </label>)}
          <Button type="submit" disabled={busy}>Simpan alamat</Button>
        </form>
      </details>
      <Button disabled={busy || !selected || !cart?.groups.length} onClick={() => void quote()}>{busy ? "Memproses…" : "Buat sesi & lihat ongkir"}</Button>
    </div>
    <div className="space-y-6">
      <h2 className="text-lg font-semibold">Ringkasan server</h2>
      {(session?.snapshot.cart.groups ?? cart?.groups ?? []).map((g, i) => <div key={i} className="rounded-card border border-divider p-4">
        <h3 className="font-medium">{modeLabel[g.mode]} · ETA {g.eta ?? "belum tersedia"}</h3>
        {g.items.map((line) => <p key={line.id} className="mt-2 flex justify-between gap-4 text-sm"><span>{line.name} × {line.quantity}</span><span>{rupiah(line.line_total.amount_minor)}</span></p>)}
      </div>)}
      {shipping?.groups.map((g) => <fieldset key={g.group_index} className="space-y-3 rounded-card border border-divider p-4">
        <legend className="px-1 font-medium">Kurir · {modeLabel[g.mode]}</legend>
        {g.options.length === 0 && <p>Tidak ada layanan untuk grup ini. Coba alamat lain.</p>}
        {g.options.map((r) => { const key = r.courierCode + ":" + r.serviceCode; return <label key={key} className="flex min-h-touch items-center gap-3">
          <input type="radio" name={"shipping-" + g.group_index} value={key} checked={choices[g.group_index] === key} onChange={() => setChoices({ ...choices, [g.group_index]: key })} className="accent-brand-text" />
          <span>{r.courierCode.toUpperCase()} · {r.serviceName} · {rupiah(r.amountMinor)}<br /><small>ETA: {r.etaMinDays === null ? "belum tersedia" : r.etaMinDays + "–" + r.etaMaxDays + " hari"}</small></span>
        </label>; })}
      </fieldset>)}
      <div className="space-y-2 rounded-card bg-brand-ink p-5">
        <p>Subtotal: {rupiah(session?.subtotal.amount_minor ?? cart?.subtotal.amount_minor ?? 0)}</p>
        {session && <><p>Estimasi ongkir terendah: {rupiah(session.shipping_estimate.amount_minor)}</p><p className="font-semibold">Estimasi total: {rupiah(session.total.amount_minor)}</p><p className="text-xs">Sesi berlaku hingga {new Date(session.expires_at).toLocaleString("id-ID")}.</p></>}
        <p className="text-sm">Pilihan kurir sementara; estimasi server memakai tarif terendah per grup. Konfirmasi ongkir dan pembayaran hadir di P1.05. Belum ada reservasi stok.</p>
      </div>
      <Button block disabled>Bayar</Button>
    </div>
  </div>;
}
