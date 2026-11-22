import { CartPanel } from "@/components/shop/cart";
export const dynamic = "force-dynamic";
export default function CartPage() {
  return <main id="konten" tabIndex={-1} className="mx-auto max-w-content space-y-6 p-6"><h1 className="text-2xl font-semibold">Keranjang</h1><CartPanel /></main>;
}
