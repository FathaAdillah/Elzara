import { api, apiBaseUrl } from "./api";

/**
 * Sesi storefront = cookie Sanctum `elzara_web_session` (HttpOnly, ADR-007 amandemen,
 * Doc 12 §3.1). JavaScript tidak pernah melihat sesi; ia hanya membaca cookie
 * `XSRF-TOKEN` lalu mengirimnya sebagai `X-XSRF-TOKEN` di setiap mutasi.
 */
export function readXsrfToken(): string | undefined {
  const match = document.cookie.split("; ").find((row) => row.startsWith("XSRF-TOKEN="));
  return match ? decodeURIComponent(match.slice("XSRF-TOKEN=".length)) : undefined;
}

/**
 * `/sanctum/csrf-cookie` berada di luar `/api/v1` dan tidak dimodelkan sebagai respons
 * JSON, sehingga ini satu-satunya `fetch` manual selain PUT presigned (Doc 12 §3.7).
 */
export async function ensureCsrfCookie(): Promise<void> {
  if (readXsrfToken()) return;
  const origin = apiBaseUrl.replace(/\/api\/v\d+\/?$/, "");
  await fetch(`${origin}/sanctum/csrf-cookie`, { credentials: "include" });
}

export function xsrfHeader(): { "X-XSRF-TOKEN": string } {
  return { "X-XSRF-TOKEN": readXsrfToken() ?? "" };
}

export function socialLoginUrl(provider: "google" | "x"): string {
  return `${apiBaseUrl}/auth/social/${provider}/redirect`;
}

export { api };
