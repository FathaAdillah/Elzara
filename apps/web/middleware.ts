import { NextResponse, type NextRequest } from "next/server";

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
  const res = NextResponse.next({ request: { headers: new Headers(req.headers) } });
  if (PRIVATE_PREFIXES.some((prefix) => req.nextUrl.pathname.startsWith(prefix))) {
    res.headers.set("Cache-Control", "no-store, max-age=0");
  }
  return res;
}

/** `/health` harus selalu bisa di-probe tanpa ikut aturan privat (Doc 01 §11.1). */
export const config = {
  matcher: ["/((?!_next|favicon.ico|health|api/).*)"],
};
