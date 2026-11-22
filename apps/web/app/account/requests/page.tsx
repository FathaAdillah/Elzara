import type { Metadata } from "next";
import Link from "next/link";
import { getProduct } from "@/lib/catalog-api";
import { RequestOrderForm } from "@/components/shop/request-order-form";

export const metadata: Metadata = {
  title: "Request Order",
  robots: { index: false, follow: false },
};

export default async function RequestOrderPage({
  searchParams,
}: {
  searchParams: Promise<{ product?: string | string[] }>;
}) {
  const { product } = await searchParams;
  const slug = typeof product === "string" && /^[a-z0-9-]{1,200}$/.test(product) ? product : undefined;
  const result = slug ? (await getProduct(slug))?.data : undefined;
  const item = result?.kind === "request_order" ? result : undefined;
  const next = item ? `/account/requests?product=${encodeURIComponent(item.slug)}` : "/account/requests";

  return (
    <main id="konten" tabIndex={-1} className="mx-auto max-w-3xl space-y-6 p-6 sm:py-10">
      <Link href={item ? `/catalog/${item.slug}` : "/catalog"} className="inline-flex min-h-touch items-center text-sm underline">
        {item ? "Kembali ke produk" : "Kembali ke katalog"}
      </Link>
      <header className="space-y-2">
        <p className="text-xs font-medium uppercase tracking-widest text-brand-text">Request Order</p>
        <h1 className="text-3xl font-semibold tracking-tight">Ajukan permintaan barang</h1>
        <p className="text-sm leading-relaxed text-ink-muted">
          Beri tahu kami barang yang Anda cari. Tim kami meninjau pengajuan privat dalam dua hari kerja.
          Ini belum menjadi pesanan dan tidak ada pembayaran sekarang.
        </p>
      </header>
      <RequestOrderForm key={next} initialTitle={item?.name} loginHref={`/account/login?next=${encodeURIComponent(next)}`} />
    </main>
  );
}
