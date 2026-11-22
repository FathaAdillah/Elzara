/**
 * Panggilan API dari server storefront semuanya berasal dari IP container web, jadi kuota
 * `rate:public` per-IP di Laravel akan dibagi semua pembeli. Server meneruskan IP pembeli
 * yang ditulis edge nginx (`X-Real-IP`, ditimpa nginx sehingga tidak bisa dipalsukan klien)
 * bersama kunci server-ke-server; API hanya memercayai header IP bila kuncinya cocok.
 * Kunci tidak pernah masuk `NEXT_PUBLIC_*` dan hanya dibaca di server.
 */
export const STOREFRONT_KEY_HEADER = "X-Elzara-Storefront-Key";
export const SHOPPER_IP_HEADER = "X-Elzara-Shopper-Ip";

export function shopperIdentityHeaders(
  incoming: Pick<Headers, "get">,
  key: string | undefined,
): Record<string, string> {
  const shopperIp = incoming.get("x-real-ip")?.trim();
  if (!key || !shopperIp) return {};
  return { [STOREFRONT_KEY_HEADER]: key, [SHOPPER_IP_HEADER]: shopperIp };
}
