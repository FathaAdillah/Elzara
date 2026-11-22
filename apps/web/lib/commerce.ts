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
  constructor(public problem: { title?: string; code?: string; errors?: { pointer: string; detail: string }[] }) {
    super(problem.title ?? "Permintaan gagal. Silakan coba lagi.");
  }
}
