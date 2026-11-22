import { api } from "./api";
import { ensureCsrfCookie, xsrfHeader } from "./session";
import { CommerceError, type AddressWrite } from "./commerce";
async function result<T>(request: Promise<{ data?: T; error?: unknown; response: Response }>): Promise<T> {
  const { data, error, response } = await request;
  if (response.status === 401 && typeof window !== "undefined") {
    window.location.assign("/account/login?next=" + encodeURIComponent(window.location.pathname));
  }
  if (!response.ok) throw new CommerceError({ ...(typeof error === "object" && error !== null ? error : {}), status: response.status });
  return data as T;
}
async function mutation() {
  await ensureCsrfCookie();
  return { credentials: "include" as const, cache: "no-store" as const, params: { header: { ...xsrfHeader(), "Idempotency-Key": crypto.randomUUID() } } };
}
const read = { credentials: "include" as const, cache: "no-store" as const };
export const getCart = () => result(api.GET("/cart", read));
export const getAddresses = () => result(api.GET("/me/addresses", read));
export async function addCartItem(variant_id: string) { return result(api.POST("/cart/items", { ...await mutation(), body: { variant_id, quantity: 1 } })); }
export async function updateCartItem(id: string, quantity: number) { const options = await mutation(); return result(api.PATCH("/cart/items/{id}", { ...options, params: { ...options.params, path: { id } }, body: { quantity } })); }
export async function removeCartItem(id: string) { const options = await mutation(); return result(api.DELETE("/cart/items/{id}", { ...options, params: { ...options.params, path: { id } } })); }
export async function saveAddress(body: AddressWrite) { return result(api.POST("/me/addresses", { ...await mutation(), body })); }
export async function createCheckout(shipping_address_id: string, expected_items_hash: string) { return result(api.POST("/checkout/sessions", { ...await mutation(), body: { shipping_address_id, expected_items_hash } })); }
export async function shippingOptions(id: string) {
  await ensureCsrfCookie();
  return result(api.POST("/checkout/sessions/{id}/shipping-options", { ...read, params: { header: xsrfHeader(), path: { id } } }));
}
export async function selectShipping(id: string, selections: { group_index: number; courier_code: string; service_code: string }[]) {
  const options = await mutation();
  return result(api.PUT("/checkout/sessions/{id}/shipping-selection", { ...options, params: { ...options.params, path: { id } }, body: { selections } }));
}
