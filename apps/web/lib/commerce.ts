import type { components } from "@elzara/api-client";
export type Cart = components["schemas"]["CartRead"];
export type Address = components["schemas"]["AddressRead"];
export type AddressWrite = components["schemas"]["AddressWrite"];
export type Checkout = components["schemas"]["CheckoutSessionRead"];
export type Shipping = components["schemas"]["ShippingOptionsRead"];
export const modeLabel: Record<string, string> = { rts: "Siap kirim", pre_order: "Pre-order", request_order: "Request order" };
export function rupiah(amount: number): string {
  return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(amount);
}
export function validQuantity(value: string): number | undefined {
  if (!/^[0-9]+$/.test(value)) return undefined;
  const n = Number(value);
  return Number.isInteger(n) && n >= 1 && n <= 99 ? n : undefined;
}
export class CommerceError extends Error {
  constructor(public problem: { title?: string; detail?: string; status?: number; code?: string; errors?: { pointer: string; detail: string }[] }) {
    const messages: Record<string, string> = {
      BATCH_QUOTA_EXHAUSTED: "Kuota batch habis. Kurangi jumlah atau pilih batch lain.",
      BATCH_NOT_OPEN: "Batch belum dibuka atau sudah ditutup. Pilih batch yang masih terbuka.",
      BATCH_ACCOUNT_LIMIT_EXCEEDED: "Jumlah pesanan melebihi batas per akun untuk batch ini.",
      CHECKOUT_MIXED_MODES: "Pisahkan produk siap kirim dan pre-order ke pesanan berbeda.",
      CHECKOUT_SNAPSHOT_STALE: "Keranjang Anda berubah. Periksa kembali lalu lanjutkan checkout.",
      CHECKOUT_SESSION_EXPIRED: "Sesi checkout telah berakhir. Silakan mulai checkout kembali.",
      CART_ITEM_UNAVAILABLE: "Barang tidak tersedia atau stok tidak cukup. Periksa jumlah di keranjang.",
      CART_EMPTY: "Keranjang Anda kosong. Tambahkan produk sebelum melanjutkan checkout.",
      ADDRESS_INVALID: "Alamat pengiriman belum valid. Periksa kembali kolom yang ditandai.",
      SHIPPING_WEIGHT_MISSING: "Berat produk belum tersedia. Hubungi kami untuk bantuan pengiriman.",
      SHIPPING_PROVIDER_UNAVAILABLE: "Penyedia pengiriman sementara tidak tersedia. Silakan coba lagi.",
      SHIPPING_QUOTES_REQUIRED: "Muat pilihan pengiriman terlebih dahulu sebelum memilih kurir.",
      SHIPPING_SELECTION_INCOMPLETE: "Pilih satu layanan pengiriman untuk setiap grup.",
      SHIPPING_SELECTION_INVALID: "Pilihan pengiriman tidak lagi tersedia. Muat ulang pilihan kurir.",
      SHIPPING_SELECTION_REQUIRED: "Pilih layanan pengiriman sebelum memesan stok.",
      INSUFFICIENT_STOCK: "Stok tidak cukup. Periksa jumlah barang di keranjang.",
      PAYMENT_INTENT_EXPIRED: "Waktu pembayaran habis. Buat sesi checkout baru.",
      FAKE_PAYMENT_DISABLED: "Pembayaran simulasi tidak tersedia.",
      CHECKOUT_SESSION_CONVERTED: "Sesi ini sudah digunakan untuk membuat pesanan.",
    };
    const fallback = [problem.detail, problem.title].find((text) => text?.trim() && !/^[A-Z][A-Z0-9_]*$/.test(text.trim()));
    super(messages[problem.code ?? ""] ?? (problem.code?.startsWith("SHIPPING_") ? "Pengiriman belum tersedia. Periksa alamat atau coba lagi." : problem.status === 503 ? "Layanan sementara tidak tersedia. Silakan coba lagi." : fallback ?? "Permintaan gagal. Silakan coba lagi."));
  }
}
