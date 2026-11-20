import createClient from "openapi-fetch";
import type { paths } from "@elzara/api-client";

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
 * Arah ADR-007 (masih `Proposed`, menunggu threat model / Q-2): katalog publik GET boleh
 * langsung ke API/CDN; cart, checkout, dan area akun cenderung lewat BFF (Doc 01 §16,
 * ADR-007 D-1). Yang berubah karenanya hanyalah TEMPAT klien ini dipanggil (Doc 12
 * §1.3 / ADR-007 D-3: kontrak tidak boleh bercabang) — bukan klien-nya.
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
