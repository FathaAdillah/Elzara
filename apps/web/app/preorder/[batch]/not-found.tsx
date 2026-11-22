import Link from "next/link";

export default function NotFound() {
  return <main id="konten" tabIndex={-1} className="mx-auto max-w-content space-y-4 p-6">
    <h1 className="text-2xl font-semibold">Batch tidak ditemukan</h1>
    <p>Batch tidak tersedia atau jendela pemesanannya sudah ditutup.</p>
    <Link href="/preorder" className="underline">Lihat batch terbuka</Link>
  </main>;
}
