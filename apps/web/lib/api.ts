import createClient from "openapi-fetch";
import type { paths } from "@elzara/api-client";
import { shopperIdentityHeaders } from "./shopper-identity";
import { childTraceparent } from "./trace";

/**
 * Satu-satunya jalur frontend → API adalah klien TypeScript TER-GENERATE dari
 * `packages/api-contracts` (nama paketnya `@elzara/api-client`; Doc 01 §4 butir 1-2,
 * Doc 12 §1.3). Tidak ada `fetch(url)` manual di kedua app; satu-satunya pengecualian
 * `PUT` presigned ke MinIO, yang bukan endpoint kita (Doc 12 §3.7).
 *
 * Browser dan container melihat hostname yang berbeda — pelajaran yang sama dengan dua
 * endpoint MinIO (`MINIO_PUBLIC_ENDPOINT` vs endpoint internal, Doc 01 §9.2):
 *   - browser / edge        → `NEXT_PUBLIC_API_BASE_URL`
 *   - server component      → `API_INTERNAL_URL` (hostname service Docker; tidak pernah
 *     dilihat browser, dan request yang mencoba `http://api:9000` dari browser akan
 *     gagal tanpa pesan yang jelas bagi pengguna)
 *
 * Sesi storefront memakai cookie Sanctum same-origin (ADR-007 amandemen, Doc 12 §3.1);
 * helper CSRF dan URL login sosial ada di `lib/session.ts`.
 * Secret tidak pernah masuk `NEXT_PUBLIC_*` (Doc 01 §9.3, ADR-007 D-4).
 */
const PUBLIC_BASE_URL = process.env.NEXT_PUBLIC_API_BASE_URL ?? "/api/v1";

function internalBaseUrl(): string {
  const internal = process.env.API_INTERNAL_URL;
  if (!internal) return PUBLIC_BASE_URL;
  return internal.replace(/\/+$/, "").replace(/\/api\/v\d+$/, "") + "/api/v1";
}

export const apiBaseUrl: string =
  typeof window === "undefined" ? internalBaseUrl() : PUBLIC_BASE_URL;

export const api = createClient<paths>({ baseUrl: apiBaseUrl });

// Server component meneruskan trace request halaman ke API (web → api → job, Doc 01 §12)
// dan identitas pembeli untuk rate limit per-pembeli (lib/shopper-identity.ts).
// Di luar request scope (build, ISR) `headers()` melempar; request tetap jalan sebagai trace baru di API.
if (typeof window === "undefined") {
  api.use({
    async onRequest({ request }) {
      try {
        const { headers } = await import("next/headers");
        const incomingHeaders = await headers();
        const incoming = incomingHeaders.get("traceparent");
        if (incoming && !request.headers.has("traceparent")) {
          request.headers.set("traceparent", childTraceparent(incoming));
        }
        const identity = shopperIdentityHeaders(
          incomingHeaders,
          process.env.ELZARA_STOREFRONT_PROXY_KEY,
        );
        for (const [name, value] of Object.entries(identity))
          request.headers.set(name, value);
      } catch {
        // tanpa request scope
      }
      return request;
    },
  });
}
