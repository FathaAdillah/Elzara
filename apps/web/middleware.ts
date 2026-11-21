import { NextResponse, type NextRequest } from "next/server";
import { childTraceparent } from "./lib/trace";

/**
 * Route-guard non-otoritatif untuk halaman privat customer (Doc 04 §1: `middleware.ts`
 * "hanya route-guard non-otoritatif (UI), bukan pengganti policy"). Yang PUTUS di sini
 * hanyalah caching: halaman privat tidak boleh masuk CDN/ISR (Doc 01 §2.4, Doc 04 §8).
 *
 * Sesi storefront adalah cookie Sanctum `elzara_web_session` yang HttpOnly (ADR-007
 * amandemen, Doc 12 §3.1); halaman masuk ada di `/account/login`. Tidak ada redirect di
 * sini karena keberadaan cookie bukan bukti sesi aktif: keputusan tetap di guard `web`
 * Laravel, yang menjawab 401 untuk sesi tercabut.
 */
const PRIVATE_PREFIXES = ["/cart", "/checkout", "/account"];

export function middleware(req: NextRequest) {
  const nonce = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))));
  let mediaOrigin = "'self'";
  if (process.env.MEDIA_PUBLIC_ORIGIN) {
    const media = new URL(process.env.MEDIA_PUBLIC_ORIGIN);
    if (media.protocol === "http:" || media.protocol === "https:") mediaOrigin = media.origin;
  }
  const policy = `default-src 'self'; script-src 'self' 'nonce-${nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: ${mediaOrigin}; font-src 'self'; connect-src 'self' ${mediaOrigin}; frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'`;
  const headers = new Headers(req.headers);
  headers.set("Content-Security-Policy", policy);
  headers.set("x-nonce", nonce);
  headers.set("traceparent", childTraceparent(req.headers.get("traceparent")));
  const res = NextResponse.next({ request: { headers } });
  res.headers.set("Content-Security-Policy", policy);
  if (PRIVATE_PREFIXES.some((prefix) => req.nextUrl.pathname.startsWith(prefix))) {
    res.headers.set("Cache-Control", "no-store, max-age=0");
  }
  return res;
}

/** `/health` harus selalu bisa di-probe tanpa ikut aturan privat (Doc 01 §11.1). */
export const config = {
  matcher: ["/((?!_next|favicon.ico|health|api/).*)"],
};
