# Dokumen 04 — Frontend Tailwind & Strategi Tema "Tinggal Replace"

Tanggal: 2026-11-16 · Direvisi: 2026-11-17 mengikuti Blueprint.
**Rujukan induk:** SNOWEGO_MICROSERVICES_BLUEPRINT.md v1.0 — keputusan mengikuti Blueprint (ADR-003 K-1); resolusi konflik di Doc 11.

Stack: **dua aplikasi TypeScript yang di-deploy mandiri** — storefront `web` (Next.js/React) dan panel `admin` (Nuxt/Vue dengan Nuxt UI) — memakai **Tailwind CSS v4** (CSS-first `@theme`) dan memakan **TypeScript client hasil generate** dari `packages/api-contracts` (Blueprint §2.1/§3.1/§7; resolusi B-1/B-2 di Doc 11 §1). Bukan lagi satu aplikasi Inertia untuk storefront + admin.

Prinsip yang **tidak berubah**: **komponen tidak tahu brand**. Brand = data (token + config + copy), bukan kode komponen. Ini tetap memungkinkan klien A → klien B hanya dengan mengganti token + config, tanpa menyentuh logika UI. Blueprint mengubah **cara pengiriman (delivery)**, bukan **desain token** — sistem token tiga lapis, config brand setara, tiga preset, inventaris komponen + slot branding, aturan aksesibilitas, dan checklist ganti brand semuanya dipertahankan di bawah. Yang berubah adalah: dari satu app Inertia menjadi dua app Next, dan dari props Inertia menjadi API client hasil generate + server components.

Batas yang mengikat (tetap, tidak dilunakkan Blueprint — ADR-003 K-5): seluruh komponen, ikon, ilustrasi, dan token di dokumen ini orisinal/dibuat untuk proyek ini. Kami tidak menyalin CSS, markup, screenshot sebagai aset, logo, font berlisensi, atau teks marketing dari situs mana pun. Foto produk & kata-kata brand diisi klien.

## 0. Yang berubah / Yang tetap (tabel audit)

| Aspek | **Tetap** (Blueprint tidak mengubahnya) | **Berubah** (karena Blueprint / B-1/B-2) |
|---|---|---|
| Sistem token | Tiga lapis (primitive → semantik → komponen/slot); komponen hanya menyebut Lapis 2/3 | Lokasi & cara build: token CSS jadi milik `packages/ui`, di-resolve per app, bukan satu `app.css` Inertia |
| Config brand | brand = data; nama/kontak/legal/nav tidak di-hardcode komponen | dari `config/brand.php` (satu app) → modul brand berbagi di `packages/ui` + API Laravel tetap sumber data dinamis; dikonsumsi DUA app |
| Preset tema | Tiga preset orisinal (`playful`, `clean-premium`, `bold-kpop`) | tak berubah isinya; build per app lewat env `BRAND_THEME` |
| Inventaris komponen | Daftar primitif `ui/` + `shop/` + slot branding | primitif pindah ke `packages/ui`; `admin/*` pindah ke app `admin`; sisanya per app |
| Aksesibilitas | Semua aturan kontras/fokus/alt/touch/reduced-motion | tak berubah |
| Pengambilan data | — | **props Inertia → API client generate (B-2) + server components / route handlers**; bentuk BFF **belum diputuskan (ADR-007)** |
| Performa | Budget CWV tetap diuji, bukan diasumsikan | ditambah pemetaan static/ISR/edge, "stok bukan cache", image pipeline MinIO/CDN (Blueprint §8.6/§11/§12) |
| Aplikasi admin | — | app **sendiri**, RBAC **10 role** (B-10); menyembunyikan menu ≠ otorisasi |
| Ganti brand | Isi checklist langkah 1–10 | propagasi ke **kedua** app + risiko "lupa di satu app" |

## 1. Layout berkas (monorepo Blueprint §3.1)

```
packages/
  ui/                       # SATU sumber token + primitif, dipakai kedua app
    src/
      tokens/
        primitives.css       # Lapis 1: skala abu/brand/aksen, tipe, radius, shadow, spacing, z, motion
        semantic.css         # Lapis 2: surface, ink, brand, accent, po, ready, quote, focus-ring, ...
        slots.css            # Lapis 3: --cart-drawer-width, --pdp-gallery-ratio, --chart-1..6, ...
        presets/
          playful.tokens.css
          clean-premium.tokens.css
          bold-kpop.tokens.css
      components/
        primitives/          # Button, Input/Textarea/Select, Card, Badge, Modal/Drawer, Tabs,
                             #   Toast, Skeleton, EmptyState, Pagination, Stepper  (bebas brand)
      formatting/            # useMoney (IDR), tanggal/tz, numeral — tanpa DOM
      brand/
        brand.config.ts       # tipe + default brand (nama, legal, kontak, nav, tautan, seo)
        schema.ts             # zod/valibot; divalidasi saat build
      tailwind.preset.ts      # @theme hanya memetakan var(--color-*) → utilitas Tailwind
    index.ts
  api-contracts/            # OpenAPI 3.1 (source of truth) + TS client HASIL GENERATE (B-2)
    openapi/api.v1.yaml
    generated/client.ts       # jangan disunting manual; di-regenerate di CI

apps/
  web/             # Next.js storefront publik  (Blueprint §2.1)
    app/                     # App Router: (catalog)/, product/[slug], cart, checkout, account/*
    components/shop/         # ProductCard, VariantPicker, PoCountdown, CartDrawer, CheckoutStepper…
    lib/api.ts               # instance client generate (mode publik / BFF — lihat §7)
    config/brand.ts          # re-export brand aktif; TIDAK mendefinisikan ulang token
    middleware.ts            # hanya route-guard non-otoritatif (UI), bukan pengganti policy
  admin/           # Next.js admin internal  (Blueprint §2.1/§12)
    app/                     # App Router: dashboard, catalog, sales, preorder, inventory, finance…
    components/admin/        # DataTable generik, chart, form operasional, audit timeline
    lib/api.ts               # client generate dengan basis URL internal + BFF session (§7)
    config/brand.ts
```

Aturan penempatan: **primitif `ui/`, token, formatting, dan tipe brand tinggal di `packages/ui`** dan boleh diimpor kedua app. **Komponen domain** (`shop/*` untuk storefront, `admin/*` untuk panel) tinggal **di app-nya masing-masing**. Yang **tidak boleh** dibagikan lewat `packages/ui`: state autentikasi/role admin, query yang menyentuh endpoint terautentikasi, komponen yang me-render data customer/admin lain, dan apa pun yang berpotensi ikut ter-bundle ke storefront yang ter-cache di CDN publik. Token = berbagi; **session & data privat ≠ berbagi**.

## 2. Arsitektur token (tiga lapis) — dipertahankan

**Lapis 1 — primitive** (tidak dipakai langsung di komponen): skala abu, skala brand, skala aksen, tipe skala, radius, shadow, spacing, z-index, motion.
**Lapis 2 — semantik** (yang dipakai komponen): `surface`, `surface-muted`, `ink`, `ink-muted`, `brand`, `brand-ink`, `accent`, `success`, `warning`, `danger`, `info`, `po` (badge pre-order), `ready` (badge ready), `quote` (badge penawaran), `focus-ring`, `overlay`, `divider`.
**Lapis 3 — komponen/slot**: `--cart-drawer-width`, `--pdp-gallery-ratio`, `--badge-radius`, `--button-height`, `--chart-1..6`. Aturan: komponen hanya menyebut Lapis 2/3; Lapis 1 hanya dirujuk oleh definisi Lapis 2.

### 2.1 Strategi CSS custom-property untuk Next.js

Tailwind v4 CSS-first: komponen memakai utilitas semantik (`bg-brand`, `text-ink-muted`), yang di-`@theme` dipetakan ke **CSS custom property** (`--color-brand`), yang akhirnya menunjuk variabel primitive. Rantai resolusi tetap satu arah: komponen → semantik → primitive → nilai konkret preset.

```css
/* packages/ui/src/tokens/semantic.css — satu-satunya tempat brand digonta-ganti */
@theme {
  /* semantik */
  --color-surface:      var(--neutral-0);
  --color-surface-muted:var(--neutral-50);
  --color-ink:          var(--neutral-900);
  --color-ink-muted:    var(--neutral-500);
  --color-brand:        var(--brand-600);
  --color-brand-ink:    var(--brand-50);
  --color-accent:       var(--coral-500);
  --color-po:           var(--violet-600);
  --color-ready:        var(--green-600);
  --color-danger:       var(--red-600);
  --font-display: "Bricolage Grotesque", ui-sans-serif;   /* harus self-hosted + lisensi OFL/SIL */
  --font-body:    "Inter", ui-sans-serif;
  --radius-card:  18px;
  --radius-pill:  999px;
  --shadow-card:  0 1px 2px rgb(0 0 0/.04), 0 8px 24px -12px rgb(0 0 0/.12);
  /* layout / slot */
  --spacing-gutter: clamp(16px, 4vw, 40px);
  --cart-drawer-width: 420px;
}
```

**Build tema per app.** Kedua app mengimpor `@elzara/ui/tokens.css` dan memilih preset pada elemen `html` lewat `data-brand-theme`. Set `BRAND_THEME` saat menjalankan build, atau gunakan build arg `NEXT_PUBLIC_BRAND_THEME` (storefront) dan `NUXT_PUBLIC_BRAND_THEME` (admin) di Docker. Storefront memakai `brand` secara default; admin memakai lavender `admin` (terang/gelap). Untuk satu preset kustom di keduanya, pilih `brand` di kedua build dan ubah hanya `packages/ui/src/tokens/presets/brand.tokens.css`. Penggantian tema tidak memerlukan perubahan komponen.

**Mirror untuk chart/canvas.** `next/image` dan ApexCharts/canvas tidak selalu bisa membaca CSS var saat render server; karena itu ada mirror TypeScript murni di `packages/ui`.

```ts
// packages/ui/src/formatting/tokens.ts — mirror untuk chart/canvas (bebas DOM, SSR-safe)
export const chartPalette = ['brand','accent','po','ready','warning','danger'] as const
```

## 3. Config brand (setara `config/brand.php`, sekarang dikonsumsi dua app)

Brand adalah data. Sumber kebenaran teks/legal/kontak dinamis tetap di **Laravel API** (`/api/v1` mengembalikan brand/nav/content), sedangkan **bentuk & default statis** didefinisikan di `packages/ui/src/brand/brand.config.ts` agar kedua app punya tipe yang sama.

```ts
// packages/ui/src/brand/brand.config.ts
export interface BrandConfig {
  name: string; tagline: string; supportWhatsapp: string;
  legal: { company: string; address: string; npwp: string; email: string };
  nav: { id: string; label: string; to: string; badge?: string }[];
  links: { terms: string; privacy: string; howToPo: string };
  social: Record<string, string>;
  locale: 'id'; currency: 'IDR'; tz: 'Asia/Jakarta';
  theme: 'playful' | 'clean-premium' | 'bold-kpop' | 'brand';  // preset storefront
  seo: { defaultOg: string; siteName: string };
}
```

Storefront mengambilnya lewat **server component** (initial props, lalu di-hydrate ke context React); admin memakai objek brand yang sama untuk header/invoice/laporan. Komponen **tidak pernah** membaca nama toko/hex dari string literal — hanya dari `brand.*` atau token. Nilai yang benar-benar rahasia (token API, kredensial gateway) **tidak pernah** masuk objek brand maupun `NEXT_PUBLIC_*` (Blueprint §12).

## 4. Preset tema (ketiganya orisinal, siap dipakai/ditiru oleh brand lain)

| Token | `playful` (imut, merchandise karakter) | `clean-premium` (minim, barang kolektibel) | `bold-kpop` (fanbase, high-energy) |
|---|---|---|---|
| brand | `#F26B9A` magenta-lembut | `#1F2430` navy-tua | `#7B2BF9` ungu-vivid |
| accent | `#FFC53D` kuning madu | `#B08D57` gold | `#00E5A0` mint neon |
| surface / muted | `#FFFDF9` / `#FDEFF5` | `#FFFFFF` / `#F4F5F7` | `#0B0B12` / `#15151F` (dark-first) |
| ink / muted | `#241C22` / `#6E6469` | `#12161D` / `#5C6472` | `#F4F4FA` / `#9A9AB0` |
| radius card | 20 px | 6 px | 14 px + border 1px |
| shadow | lembut, besar | sangat tipis | glow aksen |
| font display | rounded sans (OFL) | grotesk netral (OFL) | condensed kuat (OFL) |
| badge mode | pil + ikon | teks kecil uppercase | blok + outline |
| Kepadatan grid | longgar (2-xs/3-sm/4-lg) | padat (3/4/6) | longgar + hero asimetris |
| motion | bounce 240 ms | fade 160 ms | slide 200 ms |

Cara ganti: set `BRAND_THEME` saat build → `packages/ui` me-resolve `presets/{theme}.tokens.css`; atau override per-brand dengan menaruh `brand.tokens.css` hasil klon preset di `packages/ui`. Tidak ada komponen yang perlu diubah. **Karena sekarang dua app, override HARUS diletakkan di `packages/ui` (sumber bersama), bukan di salah satu app** — lihat §11.

## 5. Inventaris komponen + slot branding

Sprint 0.8: Button, Input, Card, Badge, Modal, ToastViewport, dan Grid adalah primitif React di `@elzara/ui`. Admin memakai komponen Nuxt UI dan grid Tailwind dengan token yang sama, tanpa memuat primitif React. Jalankan `npm ci && npm run typecheck && npm test` di `packages/ui` untuk memeriksa kontras token dan perilaku aksesibilitas primitif.

Primitif `ui/` (13, di `packages/ui`, bebas brand): Button (5 varian × 3 ukuran) · Input/Textarea/Select/Checkbox/Radio · Card · Badge · Modal/Drawer · Tabs · Toast · Skeleton · EmptyState · Pagination · Stepper.
Komponen `shop/` (22, di `web`): SiteHeader · MegaNav · SearchBar+FacetPanel · ProductCard · ProductGrid · VariantPicker (bertingkat) · ModeBadge (RTS/PO/Quote) · PoCountdown (server-time) · PriceBlock (IDR) · Gallery (+zoom) · WishlistButton · CartDrawer · CartLine · CartGroupByFulfillment · ShippingMethodPicker · AddressForm · PaymentMethodPicker · OrderSummary · CheckoutStepper · OrderTimeline · TrackingCard · QuoteOfferTable · ReviewList/Form · NotificationPref · Footer · ContentBlockRenderer (banner/hero/grid dari modul Content).
Komponen `admin/` (di `admin`): StatTile · DataTable generik (kolom/filter/paginasi/aksi massal) · ChartFrame (palette dari `tokens.ts`) · AuditTimeline · FinanceReconciliationPanel · QueueMonitorPanel · RoleAwareNav · BulkActionBar.

| Slot yang harus diisi brand | Mekanisme | Contoh | App |
|---|---|---|---|
| Logo & favicon | `public/brand/logo.svg` (currentColor-aware) | header/footer/OG | keduanya |
| Nama, tagline, legal | `brand.config.ts` + API | header, footer, invoice | keduanya |
| Nada bahasa copy | `messages/{id,en}/*` + blok Content | tombol "Ambil penawaran" vs "Beli sekarang" | web |
| Hero/banner/kategori | modul Content (via API) | homepage | web |
| Foto produk | upload klien ke MinIO (§8) | PDP, grid | web |
| Ikon mode PO/RTS | `components/shop/icons.tsx` orisinal, stroke ikut token | badge | web |
| Warna & radius | berkas token `packages/ui` | semua | keduanya |
| Font | `public/brand/fonts/` self-hosted + `@font-face` | display/body | keduanya |
| Email template | Blade/Markdown di API + logo brand | notif | API |
| Meta `theme-color`, manifest PWA | `<head>` App Router dari config | mobile browser | web |

Yang **tidak** boleh di-hardcode di komponen: nama toko, nomor WA, alamat, hex warna, px radius, font family, teks hukum. Lint rule (ESLint custom `no-restricted-syntax` untuk `#[0-9A-F]{3,8}` di `components/**` kedua app) plus cek visual di PR review.

### 5.1 Theming untuk A/B testing & multi-brand (tetap, mekanismenya pindah)

Copy slot lewat `brand.config.ts` + message catalog (mis. `checkout.cta.pay_now`) → admin bisa override per kampanye lewat modul Content (via API); varian A/B disimpan di cookie `variant` yang dibaca di server component lalu diteruskan ke context React. Dua brand = dua berkas `presets/{brand}.tokens.css` + `brand.{brand}.ts` di `packages/ui`, lalu **build matrix per brand × per app** — tanpa fork komponen. Karena sekarang dua app, matrix CI wajib membangun keempat kombinasi (brand A/web, brand A/admin, brand B/web, brand B/admin) supaya tidak ada sel yang diam-diam tertinggal tema lama.

### 5.2 Aksesibilitas & kualitas visual (gerbang tiap sprint UI — tidak berubah)

Kontras ≥4,5:1 teks / ≥3:1 non-teks untuk **setiap** preset (dicek dengan tool, bukan perkiraan mata) · `focus-visible` pakai `--color-focus-ring` dan tidak pernah `outline:none` tanpa pengganti · alt gambar produk wajib (dari field `media.alt`, fallback nama+varian) · target sentuh ≥44 px di mobile · form: label terlihat + error inline + `aria-describedby`, jangan cuma warna merah · countdown & carousel: `prefers-reduced-motion` → matikan animasi, angka tetap update · keyboard: drawer/tab/stepper bisa dinavigasi · skip-link "ke konten" · dark preset: token `surface/ink` dibalik, gambar produk tidak boleh tampil hitam di tepi (kasih padding + `mix-blend` sadar). Berlaku identik di kedua app; tabel & kontrol admin ikut aturan kontras dan focus-ring yang sama.

## 6. Pengambilan data: dari props Inertia ke API client + server components

**Sebelum:** controller Laravel menyuntik props lewat Inertia; satu komponen React menerima semuanya. **Sekarang:** tidak ada lapisan Inertia. Kontrak pindah ke `packages/api-contracts` (OpenAPI 3.1 → TS client generate, B-2). Alur umum:

- **Baca publik** (katalog, PDP, koleksi/drop, pencarian): diambil di **server component** `web` memanggil `GET /api/v1/...` lewat client generate; hasil boleh di-cache edge/ISR (§8).
- **Mutasi** (cart, checkout, order, quotation accept): lewat client generate dengan `Idempotency-Key` (Blueprint §7). Harga/diskon/stok/ongkir **dihitung ulang server-side** dari checkout snapshot; browser tidak pernah jadi sumber total (Blueprint §7).
- **Data akun & admin**: lewat endpoint terautentikasi; **mekanisme sesi Sanctum same-site dua guard sudah diputuskan (ADR-007); artefak sign-off pemilik proyek dan klien masih ditunggu, lihat §7.**

## 7. Mekanisme sesi dan alternatif awal (ADR-007)

Mekanisme sesi Sanctum same-site dua guard sudah diputuskan pemilik pada 2026-11-18 (ADR-007 D-1a–D-6a), tanpa BFF wajib dan tanpa token browser. Q-2 dijawab pemilik proyek pada 2026-11-22: klien tidak punya IdP/SSO sendiri, tidak ada provider OIDC; artefak sign-off pemilik proyek dan klien masih ditunggu. Bentuk A/B di bawah adalah perbandingan alternatif awal, bukan keputusan terbuka atau implementasi yang berlaku.

**Bentuk A — browser → API langsung (token-based).**
- Browser `web`/`admin` memanggil `api.example.com` dengan short-lived access token (+ refresh).
- Kelebihan: topologi sederhana, cache publik langsung kena CDN/edge, origin mudah di-scale.
- Konsekuensi keamanan: token hidup di browser (rawan XSS → wajib CSP ketat, `script-src 'self'`, jangan simpan di `localStorage` tanpa alasan); CORS + preflight harus di-bound per origin; refresh/rotation jadi tanggung jawab klien; permukaan serangan lebih lebar.

**Bentuk B — Next.js BFF memproksikan sesi & cookie.**
- Route handler Next.js (`/api/...` internal) berbicara ke Laravel `server-to-server`; browser hanya bicara ke origin Next.js. Sesi disimpan sebagai **cookie HttpOnly + Secure + SameSite**, dengan proteksi CSRF (Blueprint §12).
- Kelebihan: **tidak ada bearer token di browser** (mitigasi XSS terbesar), boundary autentikasi di satu tempat, cocok untuk alur sensitif (checkout, akun, seluruh admin).
- Konsekuensi keamanan: server Next ikut stateful; **wajib** `Cache-Control: no-store` + cache key benar supaya respons per-user **tidak pernah** tersimpan CDN/ISR (Blueprint §12); cookie/session policy harus diselaraskan dengan Cloudflare; CSRF tetap wajib meskipun token hilang dari browser.

**Keputusan yang berlaku:** cookie sesi Sanctum same-site dua guard, CSRF wajib, tanpa BFF wajib dan tanpa token browser (ADR-007 D-1a–D-6a). Otorisasi tetap **server-side** — Laravel policy/gate yang memutuskan, bukan Next.js — dan **jangan** menaruh secret di `NEXT_PUBLIC_*`.

## 8. Performa (Blueprint §8.6, §11, §12) — angka yang divalidasi, bukan diasumsikan

**Statis/ISR vs dinamis.**
- **SSG/ISR + edge cache (Cloudflare):** halaman katalog, PDP, halaman konten/kebijakan, halaman koleksi/drop. Revalidate singkat; response publik yang aman boleh di-cache.
- **Dinamis / `no-store`:** cart, checkout, pembayaran, halaman akun customer, seluruh `admin`. Jangan cache data customer-spesifik di CDN/ISR; pakai `Cache-Control` + cache key yang benar (Blueprint §12).

**Stok — aturan keras.** Katalog **boleh** sedikit basi (angka kuota/stok tampilan boleh datang dari Redis/ISR singkat), tetapi **kebenaran stok tidak pernah berada di cache**. Ketersediaan final ditentukan reservasi atomic di DB saat checkout (Blueprint §8.6/§8.1); cache bukan ledger dan bukan gerbang oversell.

**Image pipeline.** `next/image` (WebP/AVIF, `srcset`, lazy **kecuali** hero LCP) dengan sumber dari bucket MinIO `product-media` yang disajikan lewat **CDN terkontrol** (Blueprint §11). Private buckets (`request-attachments`, `invoice-documents`, dst.) hanya via signed URL pendek — tidak pernah di-cache publik. Font: `preload` display+body, `font-display: swap`, subset latin.

**Budget Core Web Vitals (target awal, WAJIB diukur di staging sebelum diklaim):** CSS hasil build <60 KB gzip per app · JS route storefront <150 KB gzip, admin di-chunk terpisah · CLS <0,1 (reserve aspect ratio gallery & badge) · LCP mobile <2,5 s di 4G simulasi · INP <200 ms · `prefetch` link kategori/PDP dari viewport · budget bundle di CI (fail bila naik >10 %). Angka ini **untuk divalidasi lewat lab+field test**, bukan dijamin di muka (selaras §1 Blueprint: "perlu diuji, bukan jaminan").

**Berlaku per aplikasi, bukan gabungan.** Kedua aplikasi Next.js (`web` dan `admin`) di-deploy mandiri, maka **CSP dan budget bundle diuji per aplikasi**: masing-masing app mengirim CSP-nya sendiri (aturan header dan kebutuhan host-nya berbeda — publik vs internal, lihat §7/§9 dan Doc 08 §6) dan masing-masing punya anggaran JS/CSS sendiri yang dijaga CI-nya sendiri; angka gabungan lintas app tidak dipakai sebagai gerbang.

## 9. Aplikasi admin (Blueprint §4 menu, §12) — detail khusus

- **Navigasi sadar-role untuk 10 role** (superadmin, catalog-manager, sales-ops, preorder-ops, sourcing-ops, warehouse-staff, procurement-staff, finance, customer-support, analyst read-only — B-10). Sidebar dibangun dari permission yang dikembalikan API, bukan daftar menu hardcoded.
- **Menyembunyikan menu BUKAN otorisasi.** Filter menu di Next.js hanya kenyamanan UI; penegakan sebenarnya ada di **Laravel policy/gate di sisi server** (Blueprint §4: "Enforce permissions di Laravel policy/gate, bukan hanya menyembunyikan menu"). Ini melanjutkan temuan Doc 08 §3: situs sumber mengembalikan shell `/admin` HTTP 200 ke tamu dan menjaganya di klien — **pola itu tidak kita tiru**. Rute & aksi admin dijaga server-side; respons 401/403 dari API adalah garis pertahanan, bukan redirect klien.
- **Dashboard operasional:** Overview/KPI penjualan/pemantauan order/pembayaran/alert, rekonsiliasi finance, monitor antrian (queue lag/DLQ), audit timeline, bulk action (Blueprint §12).
- `admin` memakai **cookie sesi Sanctum HttpOnly/SameSite dengan guard terpisah**, tanpa BFF wajib atau token browser (ADR-007); MFA, IP restriction/Zero Trust, dan session policy terpisah dari publik (Blueprint §12) adalah kebutuhan app ini, bukan app publik.

## 10. Testing — Playwright per aplikasi (Blueprint §16)

**Storefront (`web`) journeys:** browse → PDP (varian 2 sumbu) → tambah keranjang → checkout → redirect bayar → lacak order; ikut batch PO dengan kuota; submit request produk + unggah lampiran (presigned, §8); terima quotation versi tertentu. Verifikasi: badge mode, token warna, OG image, dan **bahwa total dihitung ulang server** (payload browser diutak-atik → tetap benar).

**Admin (`admin`) journeys:** login + MFA → nav per role → ubah status order → tutup batch (idempotent) → rekonsiliasi invoice → unggah media/label → lihat audit trail. **Tes batas otorisasi eksplisit:** user role rendah memanggil endpoint admin langsung harus dapat 403 **dari server**, meski menu-nya memang sudah tidak tampil — membuktikan §9.

**Catatan jujur konsekuensi dua app.** Next.js menambah **satu deployment kedua, satu pipeline build kedua, dan satu tempat lagi tempat ganti brand bisa terlupa**. Mitigasi di CI: (1) kedua app mengunci versi `packages/ui` token + `brand.config` yang sama (assert di build matrix); (2) grep literal hex/string brand lama dijalankan di `apps/web/**` **dan** `apps/admin/**`; (3) Playwright dijalankan terhadap kedua app di staging. Jangan sampai storefront sudah bertema baru sementara admin masih preset lama.

## 11. Prosedur "ganti brand" (checklist 30–60 menit, kini untuk DUA app)

1. Salin `packages/ui/.../presets/{preset}.tokens.css` → `brand.tokens.css` **di packages/ui (sumber bersama)**, isi warna/typografi/radius brand baru.
2. Cek kontras semua pasangan semantik (tool) → sesuaikan skala primitive di `packages/ui`, bukan komponen.
3. Ganti `public/brand/*` (logo.svg, logo-mark, favicon set, og-default, manifest) **di kedua app** — file orisinal milik brand itu.
4. Isi `brand.config.ts` (nama, legal, kontak, nav, tautan kebijakan) di `packages/ui`, dan variabel env per app (`BRAND_THEME`, `APP_NAME`, `ASSET_URL`, `NEXT_PUBLIC_*` yang bukan rahasia).
5. Buat 1 `ContentBlock` hero + banner + halaman panduan + T&C (copy dari klien) lewat API/admin.
6. Upload font berlisensi + `@font-face` brand, atau pakai preset.
7. Ganti template email & invoice (nama, logo, footer legal) di sisi API.
8. **Build KEDUA app dengan versi token/config yang sama**: `BRAND_THEME=… pnpm --filter web build` **dan** `pnpm --filter admin build`.
9. Verifikasi visual: buka di storefront `/` · `/catalog` · PDP · cart · checkout · `Pesanan Saya` · halaman quote **DAN** di admin dashboard · katalog · order · penagihan → cek badge mode, warna, contrast, OG image, `theme-color` di keduanya.
10. Set meta SEO/OG, robots, sitemap, analytics, `theme-color`; lalu grep hex literal & string brand lama di `apps/web/**` dan `apps/admin/**` — CI tidak boleh lolos bila salah satu app masih preset lama.

## 12. Yang sengaja tidak dilakukan

Tidak membuat "tema" dengan fork komponen; tidak menulis utility arbitrer (`mt-[13px]`, `text-[#123456]`) di komponen; tidak menyimpan warna di JS state; tidak memakai komponen library UI yang memaksakan token sendiri tanpa adapter (kalau pakai shadcn-style primitives, token brand yang menang, bukan default library); tidak mengambil aset visual dari situs pihak lain sebagai "referensi" yang kemudian ditiru. **Tambahan pasca-Blueprint:** tidak menaruh token/brand override di dalam satu app saja (harus di `packages/ui`); tidak menaruh secret di `NEXT_PUBLIC_*`; tidak memakai satu build gabungan yang membuat bundle admin ikut ter-cache di CDN publik; dan **tidak** memutuskan bentuk BFF di dokumen ini — itu ranah ADR-007.
