import { notFound } from "next/navigation";
import Link from "next/link";
import { getBatch } from "@/lib/preorder-api";
import { formatIdr } from "@/lib/money";
import { PreorderItems } from "@/components/shop/preorder-items";

export const dynamic = "force-dynamic";

export default async function PreorderBatchPage({ params }: { params: Promise<{ batch: string }> }) {
  const result = await getBatch((await params).batch);
  if (result?.response.status === 404) notFound();
  const batch = result?.data;
  return <main id="konten" tabIndex={-1} className="mx-auto max-w-content space-y-6 p-6">
    <Link href="/preorder" className="underline">Semua pre-order</Link>
    {!batch ? <p role="alert">Batch sedang tidak tersedia. Silakan coba kembali.</p> : <>
      <h1 className="text-3xl font-semibold">{batch.name}</h1>
      <p>Status: {batch.status === "open" ? "Terbuka" : batch.status}</p>
      <p>ETA: {batch.eta_min_date ?? "Belum tersedia"} – {batch.eta_max_date ?? "Belum tersedia"}</p>
      <p>Deposit: {batch.deposit_policy.kind === "percent" ? `${batch.deposit_policy.value / 100}%` : `${formatIdr(batch.deposit_policy.value)} per baris`} (maksimal total baris).</p>
      <p>Pengiriman: ongkir ditagih saat release, bukan saat checkout deposit.</p>
      <PreorderItems batch={batch} />
    </>}
  </main>;
}
