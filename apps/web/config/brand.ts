/**
 * Brand = data, bukan kode (Doc 04 §3). Berkas ini HANYA meneruskan sumber bersama dari
 * `packages/ui` — tidak mendefinisikan ulang nama, hex, nav, legal, atau locale, karena
 * override yang diletakkan di satu app tidak ikut mengubah app lain dan itulah titik
 * rawan "ganti brand" (Doc 04 §11 langkah 4 + §12).
 *
 * Kontrak yang diharapkan dari paket (Doc 04 §1 rumah `brand/brand.config.ts`, §3 tipe
 * `BrandConfig`): `@elzara/ui` mengekspor `brand` + `BrandConfig`. Kalau ekspor itu
 * belum ada saat build pertama, yang harus ditambah adalah berkas brand di `packages/ui`
 * — BUKAN nilai di dalam app ini.
 */
export { brand, type BrandConfig } from "@elzara/ui";
