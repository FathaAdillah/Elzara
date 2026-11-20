import { NextResponse, type NextRequest } from "next/server";

/**
 * Route-guard non-otoritatif untuk halaman privat customer (Doc 04 §1: `middleware.ts`
 * "hanya route-guard non-otoritatif (UI), bukan pengganti policy"). Yang PUTUS di sini
 * hanyalah caching: halaman privat tidak boleh masuk CDN/ISR (Doc 01 §2.4, Doc 04 §8).
 *
 * Tidak ada redirect ke halaman login di kerangka ini, dan itu bukan kelalaian: bentuk
 * sesi/cookie/CSRF adalah ADR-007 yang masih `Proposed` (Q-2), dan endpoint auth belum
 * dinormakan (Doc 12 §2.4) — jadi rute login storefront belum boleh diciptakan. Setelah
 * ADR-007 tertutup, berkas ini yang menerima gerbang UI-nya; keputusan tetap di Laravel
 * Policy/Gate (Doc 12 §3.1), bukan di sini.
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
