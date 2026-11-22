"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import Link from "next/link";
import type { components } from "@elzara/api-client";
import { Button } from "@elzara/ui";
import { CommerceError } from "@/lib/commerce";
import { getProductRequests, submitProductRequest } from "@/lib/shop-api";

type Write = components["schemas"]["ProductRequestWrite"];
type Receipt = components["schemas"]["ProductRequestRead"];
type History = Awaited<ReturnType<typeof getProductRequests>>;
const statusLabel: Record<Receipt["status"], string> = {
  new: "Baru", in_review: "Ditinjau", quoted: "Ditawarkan", accepted: "Diterima",
  declined: "Ditolak", expired: "Kedaluwarsa", withdrawn: "Ditarik",
};
const date = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Jakarta" });
const input = "min-h-touch w-full rounded-md border border-control-border bg-surface px-3 py-2";
const label = "block space-y-1 text-sm font-medium";

export function RequestOrderForm({ initialTitle, loginHref }: { initialTitle?: string; loginHref: string }) {
  const [history, setHistory] = useState<History>();
  const [receipt, setReceipt] = useState<Receipt>();
  const [checking, setChecking] = useState(true);
  const [loginRequired, setLoginRequired] = useState(false);
  const [historyError, setHistoryError] = useState("");
  const [submitError, setSubmitError] = useState("");
  const [sending, setSending] = useState(false);
  const attempt = useRef<{ body: string; key: string } | null>(null);

  async function loadHistory(cursor?: string) {
    setChecking(true);
    setHistoryError("");
    try {
      const next = await getProductRequests(cursor);
      setHistory((old) => cursor && old ? { ...next, data: [...old.data, ...next.data] } : next);
    } catch (cause) {
      if (cause instanceof CommerceError && cause.problem.status === 401) {
        setHistory(undefined);
        setLoginRequired(true);
      } else {
        setHistoryError("Riwayat pengajuan tidak dapat dimuat. Silakan coba lagi.");
      }
    } finally {
      setChecking(false);
    }
  }

  useEffect(() => { void loadHistory(); }, []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (sending) return;
    const data = new FormData(event.currentTarget);
    const value = (field: string) => String(data.get(field) ?? "").trim();
    const mode = value("preferred_mode");
    const price = value("target_price");
    const body: Write = {
      title: value("title"), quantity: Number(value("quantity")),
      target_url: value("target_url") || null, origin_country: value("origin_country").toUpperCase() || null,
      preferred_mode: mode === "ready_stock" || mode === "preorder" ? mode : null,
      brand: value("brand") || null, series: value("series") || null,
      character_name: value("character_name") || null, notes: value("notes") || null,
      ...(price ? { target_price: { amount_minor: Number(price), currency: "IDR" } } : {}),
    };
    const fingerprint = JSON.stringify(body);
    if (attempt.current?.body !== fingerprint) attempt.current = { body: fingerprint, key: crypto.randomUUID() };
    setSending(true);
    setSubmitError("");
    try {
      const created = await submitProductRequest(body, attempt.current.key);
      setReceipt(created);
      attempt.current = null;
      setHistory((old) => old ? { ...old, data: [created, ...old.data.filter((row) => row.id !== created.id)] } : old);
    } catch (cause) {
      if (cause instanceof CommerceError && cause.problem.status === 401) {
        setLoginRequired(true);
        setHistory(undefined);
      } else if (cause instanceof CommerceError && cause.problem.status === 422) {
        setSubmitError("Periksa kembali isian Anda. Gunakan tautan HTTP atau HTTPS dan masukkan jumlah serta harga sebagai bilangan bulat.");
      } else {
        setSubmitError("Pengajuan belum bisa dikonfirmasi. Coba kirim ulang dengan isian yang sama.");
      }
    } finally {
      setSending(false);
    }
  }

  if (loginRequired) return (
    <section className="space-y-3 rounded-card border border-divider p-5">
      <p>Masuk untuk mengirim dan melihat pengajuan Anda.</p>
      <Link className="inline-flex min-h-touch items-center font-medium underline" href={loginHref}>Masuk untuk mengajukan</Link>
    </section>
  );

  return (
    <>
      {receipt ? (
        <section className="space-y-3 rounded-card border border-divider p-5" aria-labelledby="request-receipt">
          <h2 id="request-receipt" className="text-xl font-semibold"><span role="status">Pengajuan berhasil dikirim</span></h2>
          <p className="text-sm">Kode pengajuan: <strong>{receipt.id}</strong></p>
          {receipt.sla_due_at && <p className="text-sm">Target tanggapan: {date.format(new Date(receipt.sla_due_at))} WIB</p>}
          <Button variant="ghost" onClick={() => setReceipt(undefined)}>Ajukan barang lain</Button>
        </section>
      ) : history && (
        <form className="space-y-4 rounded-card border border-divider p-5 sm:p-6" onSubmit={submit}>
          <fieldset className="space-y-4" disabled={sending}>
            <label className={label}>
              Nama barang
              <input className={input} name="title" defaultValue={initialTitle?.slice(0, 200)} required minLength={5} maxLength={200} />
            </label>
            <div className="grid gap-4 sm:grid-cols-2">
              <label className={label}>Jumlah<input className={input} name="quantity" type="number" min={1} max={999} defaultValue={1} required /></label>
              <label className={label}>Target harga (Rp, opsional)<input className={input} name="target_price" type="number" min={0} max={999999999999} step={1} /></label>
              <label className={label}>Negara asal (opsional)<input className={input} name="origin_country" placeholder="Contoh: JP" pattern="[A-Za-z]{2}" maxLength={2} /></label>
              <label className={label}>
                Preferensi pemenuhan
                <select className={input} name="preferred_mode">
                  <option value="">Fleksibel</option>
                  <option value="ready_stock">Siap kirim</option>
                  <option value="preorder">Pre-order</option>
                </select>
              </label>
            </div>
            <label className={label}>
              Tautan referensi (opsional)
              <input className={input} name="target_url" type="url" maxLength={500} placeholder="https://…" />
            </label>
            <details>
              <summary className="min-h-touch cursor-pointer pt-3 text-sm font-medium">Merek, seri, atau karakter (opsional)</summary>
              <div className="grid gap-4 sm:grid-cols-3">
                <label className={label}>Merek<input className={input} name="brand" maxLength={120} /></label>
                <label className={label}>Seri<input className={input} name="series" maxLength={120} /></label>
                <label className={label}>Karakter<input className={input} name="character_name" maxLength={120} /></label>
              </div>
            </details>
            <label className={label}>Catatan (opsional)<textarea className={input} name="notes" rows={3} maxLength={2000} /></label>
            <div><Button type="submit" disabled={sending}>{sending ? "Mengirim…" : "Kirim pengajuan"}</Button></div>
          </fieldset>
          {submitError && <p role="alert" className="text-sm text-red-700">{submitError}</p>}
        </form>
      )}
      <section className="space-y-3" aria-labelledby="request-history">
        <h2 id="request-history" className="text-xl font-semibold">Pengajuan saya</h2>
        {!history && checking && <p role="status" className="text-sm text-ink-muted">Memuat pengajuan…</p>}
        {history && history.data.length === 0 && <p className="text-sm text-ink-muted">Belum ada pengajuan.</p>}
        {historyError && <p role="alert" className="text-sm text-red-700">{historyError}</p>}
        {historyError && <Button variant="ghost" onClick={() => void loadHistory()}>Coba lagi</Button>}
        <ul className="divide-y divide-divider">
          {history?.data.map((item) => (
            <li key={item.id} className="flex flex-wrap items-start justify-between gap-2 py-3">
              <div><p className="font-medium">{item.title}</p><p className="break-all text-xs text-ink-muted">{item.id}</p></div>
              <span className="text-sm">{statusLabel[item.status]}</span>
            </li>
          ))}
        </ul>
        {history?.meta.next_cursor && <Button variant="ghost" disabled={checking} onClick={() => void loadHistory(history.meta.next_cursor ?? undefined)}>Muat berikutnya</Button>}
      </section>
    </>
  );
}
