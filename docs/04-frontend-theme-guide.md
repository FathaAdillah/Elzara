# Dokumen 04 — Frontend Tailwind & Strategi Tema "Tinggal Replace"

Tanggal: 2026-11-16
Stack: Inertia + React + TypeScript + Tailwind CSS v4 (CSS-first `@theme`) + Vite.
Prinsip: **komponen tidak tahu brand**. Brand = data (token + config + copy), bukan kode komponen. Ini memungkinkan klien A → klien B hanya dengan mengganti 3 berkas, tanpa menyentuh logika UI.

Batas yang mengikat: seluruh komponen, ikon, ilustrasi, dan token di dokumen ini orisinal/dibuat untuk proyek ini. Kami tidak menyalin CSS, markup, screenshot sebagai aset, logo, font berlisensi, atau teks marketing dari situs mana pun. Foto produk & kata-kata brand diisi klien.

## 1. Layout berkas

```
resources/
  css/
    app.css                  # @import tailwindcss + @theme + layer base
    themes/
      brand.tokens.css       # brand aktif (diimport app.css)
      presets/
        playful.css
        clean-premium.css
        bold-kpop.css
    utilities.css            # utility custom yang terdaftar (@utility)
  config/
    brand.php                # nama, tagline, legal, kontak, WA, sosial, alamat
    nav.php                  # fallback menu kalau DB kosong
  views/app.blade.php        # <head>: font, meta brand, theme-color
resources/js/
  Pages/          Home, Catalog, Product, Cart, Checkout, Account/*, Orders/*, Quotes/*
  Components/     ui/* (primitives) + shop/* (komponen belanja) + admin/*
  Composables/    useCart, useVariant, useMoney, useCountdown, useTracking
  lib/            tokens.ts (mirror TS untuk chart/canvas), formatting.ts
public/brand/     logo.svg, logo-mark.svg, og-default.png, favicon set
```

## 2. Arsitektur token (tiga lapis)

**Lapis 1 — primitive** (tidak dipakai langsung di komponen): skala abu, skala brand, skala aksen, tipe skala, radius, shadow, spacing, z-index, motion.
**Lapis 2 — semantik** (yang dipakai komponen): `surface`, `surface-muted`, `ink`, `ink-muted`, `brand`, `brand-ink`, `accent`, `success`, `warning`, `danger`, `info`, `po` (badge pre-order), `ready` (badge ready), `quote` (badge penawaran), `focus-ring`, `overlay`, `divider`.
**Lapis 3 — komponen/slot**: `--cart-drawer-width`, `--pdp-gallery-ratio`, `--badge-radius`, `--button-height`, `--chart-1..6`. Aturan: komponen hanya menyebut Lapis 2/3; Lapis 1 hanya dirujuk oleh definisi Lapis 2.

```css
/* resources/css/themes/brand.tokens.css — satu-satunya file yang digonta-ganti */
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
  /* layout */
  --spacing-gutter: clamp(16px, 4vw, 40px);
  --cart-drawer-width: 420px;
}
```

```ts
// resources/js/lib/tokens.ts — mirror untuk ApexCharts/canvas (tidak bisa baca CSS var saat SSR)
export const chartPalette = ['brand','accent','po','ready','warning','danger'] as const
```

## 3. `config/brand.php` (semua teks/tautan legal di satu tempat)

```php
return [
  'name' => 'Nama Toko',  'tagline' => '…',  'support_whatsapp' => '+62…',
  'legal' => ['company'=>'…','address'=>'…','npwp'=>'…','email'=>'…'],
  'nav' => [ /* id, label, to, badge */ ],
  'links' => ['terms'=>…, 'privacy'=>…, 'how_to_po'=>…],
  'social' => [...], 'locale'=>'id','currency'=>'IDR','tz'=>'Asia/Jakarta',
  'theme' => 'playful',            // menentukan berkas token yang di-resolve
  'seo' => ['default_og'=>'/brand/og-default.png','site_name'=>'…'],
];
```
Dijadikan shared prop Inertia (`HandleInertiaRequests`) → komponen React hanya pernah membaca `usePage().props.brand.*`, tidak pernah hardcode nama toko/kontak.

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

Cara ganti: `BRAND_THEME=clean-premium` → blade memilih `themes/presets/{theme}.tokens.css`; atau override per-brand dengan menaruh `themes/brand.tokens.css` hasil klon preset. Tidak ada komponen yang perlu diubah.

## 5. Inventaris komponen + slot branding

Primitif `ui/` (13): Button (5 varian × 3 ukuran) · Input/Textarea/Select/Checkbox/Radio · Card · Badge · Modal/Drawer · Tabs · Toast · Skeleton · EmptyState · Pagination · Stepper.
Komponen `shop/` (22): SiteHeader · MegaNav · SearchBar+FacetPanel · ProductCard · ProductGrid · VariantPicker (bertingkat) · ModeBadge (RTS/PO/Quote) · PoCountdown (server-time) · PriceBlock (IDR) · Gallery (+zoom) · WishlistButton · CartDrawer · CartLine · CartGroupByFulfillment · ShippingMethodPicker · AddressForm · PaymentMethodPicker · OrderSummary · CheckoutStepper · OrderTimeline · TrackingCard · QuoteOfferTable · ReviewList/Form · NotificationPref · Footer · ContentBlockRenderer (banner/hero/grid dari modul Content) · StatTile (admin).

| Slot yang harus diisi brand | Mekanisme | Contoh |
|---|---|---|
| Logo & favicon | `public/brand/logo.svg` (currentColor-aware) | header/footer/OG |
| Nama, tagline, legal | `config/brand.php` | header, footer, invoice |
| Nada bahasa copy | `lang/id/*.php` + blok Content | tombol "Ambil penawaran" vs "Beli sekarang" |
| Hero/banner/kategori | modul Content (admin) | homepage |
| Foto produk | upload klien ke MinIO | PDP, grid |
| Ikon mode PO/RTS | `Components/shop/icons.tsx` orisinal, stroke ikut token | badge |
| Warna & radius | berkas token | semua |
| Font | `public/brand/fonts/` self-hosted + `@font-face` | display/body |
| Email template | `resources/views/mail/**` + logo brand | notif |
| Meta `theme-color`, manifest PWA | `app.blade.php` dari config | mobile browser |

Yang **tidak** boleh di-hardcode di komponen: nama toko, nomor WA, alamat, hex warna, px radius, font family, teks hukum. Lint rule (ESLint custom `no-restricted-syntax` untuk `#[0-9A-F]{3,8}` di `Components/**`) plus cek visual di PR review.

## 6. Theming untuk A/B testing & multi-brand

Copy slot lewat `brand.php` + `lang` (mis. `checkout.cta.pay_now`) → admin bisa override per kampanye lewat modul Content; varian A/B disimpan di cookie `variant`, dibaca shared prop; dua brand = dua direktori `themes/{brand}.tokens.css` + `brand.{brand}.php` + satu build matrix (Vite mode per brand) — tanpa fork komponen.

## 7. Aksesibilitas & kualitas visual (gerbang tiap sprint UI)

Kontras ≥4,5:1 teks / ≥3:1 non-teks untuk **setiap** preset (dicek dengan tool, bukan perkiraan mata) · `focus-visible` pakai `--color-focus-ring` dan tidak pernah `outline:none` tanpa pengganti · alt gambar produk wajib (dari field `media.alt`, fallback nama+varian) · target sentuh ≥44 px di mobile · form: label terlihat + error inline + `aria-describedby`, jangan cuma warna merah · countdown & carousel: `prefers-reduced-motion` → matikan animasi, angka tetap update · keyboard: drawer/tab/stepper bisa dinavigasi · skip-link "ke konten" · dark preset: token `surface/ink` dibalik, gambar produk tidak boleh tampil hitam di tepi (kasih padding + `mix-blend` sadar).

## 8. Performa (budget yang diuji di Sprint 7)

CSS hasil build <60 KB gzip (Tailwind v4 purge + satu tema) · JS route storefront <150 KB gzip, admin di-chunk terpisah · gambar: WebP/AVIF + `srcset` + lazy (kecuali LCP hero) · font: `preload` display+body, `font-display: swap`, subset latin · CLS <0,1 (reserve aspect ratio gallery & badge) · LCP mobile <2,5 s di 4G simulasi · `prefetch` link kategori/PDP dari viewport · bundle budget di CI (fail kalau naik >10 %).

## 9. Prosedur "ganti brand" (checklist 30–60 menit, untuk klien berikutnya)

1. Salin `themes/presets/{preset}.css` → `themes/brand.tokens.css`, isi warna/typografi/radius brand baru.
2. Cek kontras semua pasangan semantik (tool) → sesuaikan skala primitive, bukan komponen.
3. Ganti `public/brand/*` (logo.svg, logo-mark, favicon set, og-default, manifest icon) — file orisinal milik brand itu.
4. Isi `config/brand.php` (nama, legal, kontak, nav, link kebijakan) dan `.env` (`BRAND_THEME`, `APP_NAME`, `ASSET_URL`).
5. Buat 1 `ContentBlock` hero + banner + halaman panduan + T&C (copy dari klien).
6. Upload font berlisensi + `@font-face` brand, atau pakai preset.
7. Ganti template email & invoice (nama, logo, footer legal).
8. Build (`BRAND_THEME=… npm run build`), buka `/` · `/catalog` · PDP · cart · checkout · `Pesanan Saya` · halaman quote → verifikasi badge mode, warna, contrast, OG image.
9. Lint: grep hex literal & string brand lama di `resources/js/**`.
10. Set meta SEO/OG, robots, sitemap, analytics, `theme-color`.

## 10. Yang sengaja tidak dilakukan

Tidak membuat "tema" dengan fork komponen; tidak menulis utility arbitrer (`mt-[13px]`, `text-[#123456]`) di komponen; tidak menyimpan warna di JS state; tidak memakai komponen library UI yang memaksakan token sendiri tanpa adapter (kalau pakai shadcn-style primitives, token brand yang menang, bukan default library); tidak mengambil aset visual dari situs pihak lain sebagai "referensi" yang kemudian ditiru.
