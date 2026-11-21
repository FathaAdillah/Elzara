import type { NextConfig } from "next";

/**
 * Doc 01 §11.2: `next build` → `node:22` standalone; Doc 04 §1 + Task 0.8: token dan
 * primitif datang dari `packages/ui` lewat `file:../../packages/ui` sehingga sumbernya
 * ikut di-transpile. `basePath` tidak diperlukan: kedua app melayani root di hostname masing-masing.
 *
 * Gambar produk hidup di bucket MinIO `product-media` yang disajikan lewat CDN
 * TERKENDALI (Doc 01 §9.2, Doc 04 §8) — karena itu hostname sumber tidak di-hardcode
 * di sini melainkan dibaca dari variabel environment. Ini pasangan dari jebakan
 * "dua endpoint MinIO": browser harus diarahkan ke host yang benar-benar bisa ia
 * jangkau (`MINIO_PUBLIC_ENDPOINT` / edge `/storage`), bukan ke hostname Docker.
 * Bucket privat (`request-attachments`, `invoice-documents`, dst.) tidak pernah masuk
 * daftar ini: yang privat dilayani signed URL pendek atau proxy terautentikasi
 * (Doc 01 §9.2).
 */
type RemotePattern = { protocol: "http" | "https"; hostname: string; pathname: string };

function remotePattern(value: string | undefined): RemotePattern | null {
  if (!value) return null;
  try {
    const url = new URL(value);
    return {
      protocol: url.protocol === "http:" ? "http" : "https",
      hostname: url.hostname,
      pathname: "/**",
    };
  } catch {
    return null;
  }
}

const mediaOrigins: RemotePattern[] = [
  remotePattern(process.env.MEDIA_PUBLIC_ORIGIN),
  remotePattern(process.env.ASSET_URL),
].filter((pattern): pattern is RemotePattern => pattern !== null);

const nextConfig: NextConfig = {
  output: "standalone",
  transpilePackages: ["@elzara/ui"],
  env: { NEXT_PUBLIC_BRAND_THEME: process.env.BRAND_THEME ?? process.env.NEXT_PUBLIC_BRAND_THEME ?? "" },
  poweredByHeader: false,
  images: { remotePatterns: mediaOrigins },
};

export default nextConfig;
