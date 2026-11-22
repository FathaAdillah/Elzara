import Link from "next/link";
import { getBatches } from "@/lib/preorder-api";

export default async function PreordersPage() {
  const result = await getBatches();
  return <main id="konten" tabIndex={-1} className="mx-auto max-w-content space-y-6 p-6">
    <h1 className="text-3xl font-semibold">Pre-order terbuka</h1>
    {!result?.data ? <p role="alert">Daftar batch sedang tidak tersedia. Silakan coba kembali.</p> : <>
      {result.data.data.length === 0 && <p>Belum ada batch terbuka.</p>}
      {result.data.data.map((batch) => <article key={batch.id} className="space-y-2 rounded-card border border-divider p-5">
        <h2 className="text-xl font-semibold"><Link href={`/preorder/${batch.id}`} className="underline">{batch.name}</Link></h2>
        <p>ETA: {batch.eta_min_date ?? "Belum tersedia"} – {batch.eta_max_date ?? "Belum tersedia"}</p>
        <p>Tutup: <time dateTime={batch.closes_at}>{batch.closes_at}</time></p>
      </article>)}
    </>}
  </main>;
}
