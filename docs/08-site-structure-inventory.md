# Dokumen 08 — Inventaris Struktur Situs Referensi (hasil reverse engineering struktural)

Tanggal: 2026-11-16 · Status: selesai untuk sisi publik.
Alat: **REA (`rea-agents` 6.9.0)** mode pasif lewat CDP pada Chrome profil terpisah + probe HTTP (`curl`). Perintah inti: `rea list-browser-targets`, `rea analyze-web-bundle` (koleksi `endpoints` dan `javascript-routes`), `rea doctor`.

## 0. Batas metode (baca dulu)

Yang diambil di dokumen ini **hanya fakta struktural**: nama rute, kode status HTTP, nama file chunk, header respons, dan jumlah item. Tidak ada satu pun kalimat marketing, deskripsi produk, foto, ikon, logo, atau baris kode mereka yang disalin ke proyek. Bukti mentah REA disimpan di luar repo (`C:\temp\rea-evidence\`), bukan di `docs/`.

Dua hal yang sengaja **tidak** dilakukan:
- `robots.txt` mereka memasang `Content-Signal: search=yes,ai-train=no,use=reference` dan `Disallow: /` untuk puluhan crawler AI/bot (28+ terverifikasi; daftar responsnya terpotong saat dibaca, jadi jumlah sebenarnya bisa lebih — contoh yang terlihat: `ClaudeBot`, `anthropic-ai`, `GPTBot`, `CCBot`, `Google-Extended`). Kami menghormati itu: penggunaan ini bersifat referensi/struktural, bukan pengumpulan konten. **Ini juga sinyal komersial untuk klien Anda** — operator situs sumber peduli soal ini dan punya tim yang cukup sadar untuk memasang sinyal tersebut.
- Rute `/admin` ditemukan hidup (lihat §3). Kami berhenti pada "rute ini ada dan mengembalikan shell". **Tidak** ada enumerasi sub-rute admin, tidak ada percobaan login, tidak ada probe kredensial. Enumerasi permukaan admin milik pihak lain itu recon, bukan re-engineering produk.

## 1. Stack yang teridentifikasi

| Aspek | Temuan | Bukti |
|---|---|---|
| Framework | **Next.js App Router** (React Server Components), bukan SPA murni | `/_next/static/chunks/app/<rute>/page-<hash>.js`, `Next.MetadataOutlet`, `next-size-adjust` |
| Build ID / deployment | `NEXT_DEPLOYMENT_ID` = `da05602`, ditempel sebagai `?dpl=…` di semua aset | `data-dpl-id="da05602"` di `<html>`, `?dpl=da05602` di chunk/CSS/gambar |
| Edge | **Cloudflare** di depan origin | `Server: cloudflare`, `cf-cache-status: DYNAMIC`, `NEL`/`Report-To` cloudflare |
| Obfuscasi email | Cloudflare Scrape Shield aktif | `/cdn-cgi/l/email-protection#…`, `email-decode.min.js` |
| Optimasi gambar | `next/image` dengan `w=` 32→3840, `q=75` | `imageSrcSet="/_next/image?url=…&w=…"` |
| Font | self-host lewat `next/font`, variabel CSS | preload `…s.p.woff2`, `class="__variable_56ad68"` |
| Bahasa | `lang="id"` di shell (satu bahasa di HTML publik) | `<html lang="id">` |
| CSS | 4 chunk CSS per-halaman (code-splitting per rute) | `41020239…`, `e274d84f…`, `65e0ecea…`, `4bdf4136…` |
| Stabilitas origin | **Origin pernah mengembalikan Cloudflare 522** saat satu navigasi | tab `/open-order` → "522: Connection timed out", padahal curl menit sebelumnya 200 |

**Konsekuensi untuk kita.** (a) Tidak ada API JSON publik yang bisa "disamakan" — lihat §4, jadi REST API Laravel kita adalah pekerjaan baru, bukan port. (b) 522 di satu navigasi tunggal itu peringatan untuk non-functional requirement kita (Doc 01 §12): targetkan origin punya margin sendiri, dan jangan menaruh cache-busting `?dpl=` yang membuat setiap deploy membuang seluruh cache CDN seperti pola mereka.

## 2. Peta rute terverifikasi

REA mengekstrak **20 kandidat rute statis** dari 15 script yang dimuat halaman home. Semua 20 sudah ada di peta hasil observasi akun customer (Doc 05 §1) — jadi Doc 05 terkonfirmasi silang, tidak ada rute produk/akun yang bocor yang belum kita lihat. Arah sebaliknya tidak berlaku: empat rute ( `/login`, `/register`, `/forgot-password`, `/dashboard/cart`) tidak muncul di string statis home (script `layout` kena batas waktu analisis REA), dan **`/admin` tidak muncul sama sekali** — ia ditemukan lewat probe HTTP, bukan dari bundle. Dua sumber bukti, jadi keduanya kita catat terpisah di tabel berikut (kolom "Status HTTP" = hasil probe; rute statis = temuan REA).

| Rute | Status HTTP | Nama chunk `page-…` | Gating publik |
|---|---|---|---|
| `/` | 200 | `bb5e97f7f17bba96` | publik |
| `/open-order` | 200 | `67a157f3d251fddb` | publik (grid) |
| `/open-order/{slug}-{randomId}` | — | dimuat dinamis | tidak muncul di string statis; dibangun di sisi klien |
| `/drops` | 200 | `421452fade71baaa` | publik |
| `/login` | 200 | `a4a5b780b373036d` | publik |
| `/register` | 200 | `a4a5b780b373036d` | publik |
| `/forgot-password` | 200 | `78bc7f02624e91fc` | publik |
| `/dashboard` | **200** | `67a157f3d251fddb` | shell publik, isi butuh login |
| `/dashboard/orders` | **200** | `67a157f3d251fddb` | shell publik |
| `/dashboard/requests` | **200** | `5d72a15a6706921d` | shell publik |
| `/dashboard/tracking` | **200** | `6490b052015716e7` | shell publik |
| `/dashboard/invoices` | **200** | `6490b052015716e7` | shell publik |
| `/dashboard/favorites` | **200** | `4e9b8058bc856e5b` | shell publik |
| `/dashboard/cart` | **200** | `39b5019913854821` | shell publik |
| `/dashboard/checkout` | **200** | `67a157f3d251fddb` | shell publik |
| `/dashboard/profile` | **200** | `b4e1e2c062b1650a` | shell publik |
| `/admin` | **200** | `6490b052015716e7` | shell publik — **temuan, lihat §3** |
| 9 halaman konten (`/cara-belanja`, `/faq`, `/tentang-kami`, `/kontak`, `/kebijakan-pengiriman`, `/kebijakan-refund`, `/kebijakan-retur`, `/kebijakan-privasi`, `/syarat-ketentuan`) | 200 | `1f7917a78ffa5674` (sama semua) | publik |
| Slug acak (`/ini-halaman-tidak-ada-xyz`), `/search`, `/blog`, `/pesanan`, `/keranjang`, `/sitemap.xml` | **404** | — | tidak ada |

Yang **tidak** ada: `/search` sebagai rute (pencarian = overlay klien), sitemap XML (dampak SEO: crawl bergantung pada tautan internal), halaman produk di shell HTML home.

## 3. Temuan terpenting: 4 keluarga komponen, dan admin ada di aplikasi yang sama

Nama chunk yang **sama** berarti file kode sumber halaman yang sama. Ini membaca struktur internal aplikasi mereka tanpa menyentuh isinya:

| Chunk | Dipakai oleh | Interpretasi |
|---|---|---|
| `1f7917a78ffa5674` | **9 halaman konten** | satu template halaman yang digerakkan slug → CMS, bukan 9 halaman tulis-tangan |
| `6490b052015716e7` | `/dashboard/tracking`, `/dashboard/invoices`, **`/admin`** | satu komponen **tabel data generik** dipakai ulang, termasuk untuk admin |
| `67a157f3d251fddb` | `/open-order`, `/dashboard`, `/dashboard/checkout` | satu komponen grid produk / langkah |
| `a4a5b780b373036d` | `/login`, `/register` | satu form autentikasi dengan mode |
| `98a91356101492bb` (`layout`) | **semua rute** | satu layout global, tanpa route group terpisah untuk admin |

Dua kesimpulan yang mengubah dokumen kita:

1. **Panel admin bukan aplikasi terpisah.** Ia satu aplikasi, satu origin, satu layout dengan storefront, dan memakai tabel generik yang sama dengan halaman customer. Ini **memvalidasi keputusan Opsi A (modular monolith)** di ADR-001 — bukan cuma kita yang menyatukannya; tim sumber juga begitu, dan mereka berhasil. Doc 06 yang mengasumsikan "dashboard admin" sebagai permukaan tersendiri tetap benar secara fitur, tapi salah secara implikasi infra: tidak perlu service/UI terpisah.
2. **Semua shell halaman kembali 200 tanpa auth**, jadi proteksi mereka terjadi di klien (redirect setelah render) atau di pengambilan data. Ini celah yang **tidak** boleh kita tiru: di Laravel, `/admin/*` wajib dilindungi **server-side** (middleware role + policy), bukan disembunyikan di menu. Tambah sebagai aturan di Doc 01 §11.

## 4. Permukaan API: satu endpoint, itu pun bukan untuk katalog

`rea analyze-web-bundle` pada 15 script homepage (11–15 ter-parse, ~90 000 node AST, kelengkapan `partial`) menghasilkan **tepat satu** kandidat endpoint:

- `POST /api/ai/chat` — terverifikasi hidup: `GET` → **405**, `POST {}` → **400** (butuh body). Ini asisten AI yang terlihat di header (Doc 05 §7).

Tidak ada `/api/products`, tidak ada `/api/cart`, tidak ada endpoint pembayaran **dalam 15 script home yang dianalisis**. Perlu dicatat batasnya: chunk milik `/dashboard/cart` dan `/dashboard/checkout` tidak ikut dianalisis (tab katalog sedang 522), jadi kemungkinan ada panggilan klien di jalur bayar tidak bisa disangkal dari data ini. Yang bisa dikatakan dari data ini: katalog dan tagihan di halaman home **tidak** mengalir lewat REST publik, melainkan lewat RSC payload.

Konsekuensi: (a) fitur "asisten AI" adalah rute backend sendiri yang perlu kita rancang dari nol — rate limit, guard prompt, dan batasan data apa yang boleh dibaca asisten per role; (b) tidak ada kontrak API yang bisa kita jadikan spesifikasi, jadi Doc 01 §5 (kontrak publik antar modul) memang satu-satunya sumber kebenaran; (c) hasil REA punya keterbatasan yang jujur: string rute yang dibangun dari template dan perilaku server-side secara eksplisit masih `unknown` menurut REA, jadi §2 bukan jaminan daftar lengkap sisi server.

## 5. Taksonomi & slot aset yang terlihat publik

**Kategori (dipakai sebagai `?category=`)** — 8 nilai: `accessories`, `anime-character`, `chikawa`, `fashion`, `figure-plush`, `k-pop`, `lainnya`, `stationery`. Doc 02 mencatat 6 chip di area home; shell publik memperlihatkan 8 → **delta: kategori = data, minimal 8, dan URL-able**. Ini memperkuat aturan dua sumbu: kategori dan koleksi/fandom di Doc 05 §2 — perhatikan `chikawa` dan `anime-character` itu fandom, `stationery`/`accessories` itu jenis barang: memang tercampur, dan itu yang kita perbaiki di skema kita.

**Folder aset statis** (nama folder, bukan isinya): `/brand/*` (logo), `/category-icons/*` (ikon per kategori, penamaan mengikuti slug kategori), `icon.png`/`apple-icon.png` dengan `?hash` → ikon kategori adalah **aset per kategori yang diupload admin**, bukan glyph. Implikasi Doc 06 §3.2: form kategori butuh field ikon + alt text, dan `media.alt` harus diisi (gerbang aksesibilitas Doc 04 §7).

**Kontak**: nomor WhatsApp ditampilkan sebagai `wa.me/<nomor>` dan **obfuscasi Cloudflare** di footer → channel kontak utama mereka WA, konsisten dengan asumsi provider WA di Doc 00 §Asumsi.

## 6. Header keamanan yang layak kita tiru persis

Terkirim di respons HTML dan aset:

```
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'
Permissions-Policy: camera=(), microphone=(), geolocation=(self), browsing-topics=()
Cache-Control: private, no-cache, no-store, max-age=0, must-revalidate
```

Gap yang mereka punya dan **seharusnya kita tutup**: CSP mereka tidak punya `script-src`/`default-src` sama sekali, jadi proteksi XSS-nya cuma `frame-ancestors` + `nosniff`. Untuk platform yang menyimpan data alamat + tagihan, kita pasang `script-src 'self'` (+ host payment gateway yang diperlukan) sejak Sprint 0, dicatat di Doc 01 §11.

## 7. Yang tetap tidak diketahui

Struktur tab/admin di balik login · skema basis data · rails pembayaran & webhook yang sebenarnya dipakai · konstruksi slug PDP · kontrak server action (input/output) · panjang jendela batch & besaran deposit · perilaku batch tertutup · apakah tagihan TAX/EMS dihitung manual. Sebagian besar sudah ada sebagai pertanyaan terbuka di Doc 02 §7 dan Doc 06 §6 — tidak berubah, dan memang tidak bisa dijawab dari sisi publik.

## 8. Delta terhadap dokumen lain

| Dokumen | Perubahan karena temuan ini |
|---|---|
| `01-architecture.md` | Tambah baris delta **D8** (admin satu-aplikasi + tidak ada API publik) di §3.1; §8 Keamanan menerima daftar header §6 di doc ini sebagai baseline + aturan proteksi rute admin server-side + CSP `script-src`; §12 Risiko mencatat kerentanan origin 522 |
| `05-customer-feature-inventory.md` | §1 terverifikasi independen oleh REA; koreksi: `/admin` memang ada; status "login" pada rute `/dashboard/*` perlu ditulis "shell 200, data butuh login"; kategori publik 8 bukan 6 |
| `06-admin-dashboard-requirements.md` | Catat bahwa admin di situs sumber satu aplikasi dengan storefront (validasi Opsi A) + aturan proteksi server-side |
| `02-business-flow-customer-features.md` | Kategori sebagai data dengan 8 nilai awal + ikon per kategori sebagai aset terupload |
| `03-sprint-plan.md` | Tidak ada perubahan jadwal: tidak ada fitur baru yang muncul, hanya konfirmasi asumsi. Yang bertambah cuma pekerjaan hardening header/CSP (≈0,5 hari, masuk Sprint 0) |
