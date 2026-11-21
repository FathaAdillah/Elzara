export const dynamic = "force-dynamic";

/**
 * Halaman landin batch pre-order. Parameter `batch` adalah identifier batch
 * (`preorder_batches`) yang dibaca dari <code>GET /api/v1/preorder-batches/{id}</code>{" "}
 * (publik, Doc 12 §2.1) lewat klien ter-generate.
 *
 * Yang tidak boleh dilakukan halaman ini, seindah apa pun terasa di frontend:
 * - Kuota yang ditampilkan BUKAN kebenaran. Enrollment memakai conditional UPDATE
 *   <code>reserved_qty + :qty &lt;= quota</code> plus jendela buka/tutup di server
 *   (Doc 01 §6.3 K2); cache/ISR boleh sedikit basi untuk tampilan, tidak untuk
 *   ketersediaan (Doc 01 §6.2, Doc 04 §8).
 * - Countdown memakai waktu SERVER (PoCountdown, Doc 04 §5) — bukan jam browser,
 *   karena `closing` batch bersifat atomik di sisi API (Doc 01 §7.2).
 * - Nominal DP/pelunasan datang sebagai integer minor unit + `currency` dari API
 *   (Doc 01 §6.1), tidak pernah dihitung di frontend.
 *
 * Next 16 meneruskan `params` sebagai Promise; di-await di sini supaya kontrak
 * tipe-nya benar sejak kerangka.
 */
export default async function PreorderBatchPage({
  params,
}: {
  params: Promise<{ batch: string }>;
}) {
  const { batch } = await params;

  return (
    <main id="konten" tabIndex={-1} className="mx-auto flex max-w-content flex-col gap-2 p-6">
      <h1 className="text-2xl font-semibold text-ink">Pre-order</h1>
      <p className="max-w-prose text-sm text-ink-muted">
        Identifier batch dari URL: <code>{batch}</code>. Isi batch (periode, kuota,
        kebijakan DP, ETA, mode pengiriman) akan dirender dari respons{" "}
        <code>/api/v1/preorder-batches/{"{batch}"}</code>; tidak ada batch contoh di repo.
      </p>
    </main>
  );
}
