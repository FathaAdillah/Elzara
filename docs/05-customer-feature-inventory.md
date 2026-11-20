# Dokumen 05 — Inventaris Fitur per Menu (hasil observasi akun customer)

> **Catatan status (ADR-003 K-4 · 2026-11-17, direvisi 2026-11-18).** Dokumen ini adalah **rekaman observasi hanya-baca**, bukan dokumen keputusan. Semua **fakta yang dicatat di sini berlaku apa adanya sesuai yang teramati** dan tidak diubah hanya karena rujukan arsitektur berganti ke Blueprint. Yang berubah adalah **keputusan** arsitektur — kini bersumber dari Blueprint (`SNOWEGO_MICROSERVICES_BLUEPRINT.md` v1.0) dan direkonsiliasi butir-per-butir di **Doc 11**. Bila sebuah "konsekuensi fitur" di sini kini menghasilkan keputusan yang berbeda, itu **keputusan yang bergeser, bukan fakta yang salah**; Doc 11 adalah satu-satunya tempat perbedaan itu dibahas. Keputusan pemilik 2026-11-18 (Doc 11 §4.4; ADR-012 (RBAC/identitas/dependensi); ADR-007 (BFF/sesi)) yang menyentuh fitur customer dicatat di §5.7 dan §6 dengan label **keputusan**, terpisah dari fakta observasi — tidak ada fitur "teramati" yang ditambahkan.

Tanggal: 2026-11-16 · Metode: login memakai akun customer milik pemilik proyek, **hanya-baca** (tidak membuat order, tidak membayar, tidak mengubah data, tidak mencoba akses panel admin).
Cara baca dokumen ini: setiap baris adalah kemampuan yang terlihat dari sisi customer → kolom kanan adalah **konsekuensi fitur** untuk produk kita. Detail visual/teks situs sumber sengaja tidak disalin; yang diambil hanya kategori fungsi.
Catatan: 0 order & 0 request pada akun itu, jadi layar bertabel hanya memperlihatkan kolom/filter/empty-state, bukan isi dataset. State lanjut (mis. timeline order aktif) disimpulkan dari label status yang tersedia.

## 1. Peta route & menu

| Area | Route teramati | Status akses |
|---|---|---|
| Home | `/` | publik |
| Katalog/"open order" | `/open-order`, `/open-order?category={slug}` | publik |
| Detail produk (per batch) | `/open-order/{slug}-{randomId}` | publik |
| Drops | `/drops` | publik |
| Auth | `/login`, `/register`, `/forgot-password` | publik |
| Dashboard (ringkasan akun) | `/dashboard` | login |
| Pesanan | `/dashboard/orders` | login |
| Request produk | `/dashboard/requests` | login |
| Status barang | `/dashboard/tracking` | login |
| Invoice & tagihan | `/dashboard/invoices` | login |
| Favorit | `/dashboard/favorites` | login |
| Keranjang | `/dashboard/cart` | login |
| Pilihan pengiriman | `/dashboard/checkout` | login |
| Profil | `/dashboard/profile` | login |
| Konten statis | `/cara-belanja`, `/faq`, `/tentang-kami`, `/kontak`, `/kebijakan-pengiriman`, `/kebijakan-refund`, `/kebijakan-retur`, `/syarat-ketentuan`, `/kebijakan-privasi` | publik |

**Terverifikasi silang (2026-11-16, REA pass struktural — Doc 08).** 20 kandidat rute statis yang diekstrak REA dari bundle JS (tanpa login) **semuanya sudah tercatat** di peta di atas — tidak ada rute yang belum kita lihat. Dari arah sebaliknya, peta ini tetap lebih lengkap daripada bundle: rute auth dan `/dashboard/cart` tidak muncul di string statis home, dan `/admin` tidak muncul sama sekali di bundle (ditemukan lewat probe HTTP). Tiga koreksi/penguatan:
1. `/admin` memang ada sebagai rute (shell HTTP 200) — tabel di atas belum mencantumkannya karena observasi hanya-baca tidak menyentuh area admin.
2. Status "login" pada baris `/dashboard/*` lebih tepat dibaca **"shell 200, data butuh login"**: seluruh halaman dashboard mengembalikan HTTP 200 untuk tamu dan baru di-redirect di klien. Tidak ada penjagaan rute di server.
3. Chip kategori di shell publik ada **8** (`accessories`, `anime-character`, `chikawa`, `fashion`, `figure-plush`, `k-pop`, `lainnya`, `stationery`), bukan 6 seperti yang terlihat di area home saat itu — kategori jelas data, bukan hardcode.

Header: Home · Shop · Drops · Favorit · Keranjang · Akun (3 yang terakhir minta login bila guest) + search + tombol asisten AI. Sidebar akun: Ringkasan · Pesanan · Request produk · Status barang · Tagihan · Pilihan pengiriman.

## 2. Home & katalog

| Teramati | Konsekuensi fitur kita | Modul |
|---|---|---|
| Banner carousel multi-slide dengan kontrol manual | blok konten + urutan + link per slide, dikelola admin | Content |
| "Kategori Produk" sebagai chip + filter `?category=` | kategori = data (bukan hardcode), URL-able, shareable | Catalog, Content |
| Chip kategori: aksesoris, karakter A, fashion, K-pop, lainnya, figure/plush | kategori campur dimensi (jenis barang vs fandom) → kita butuh 2 sumbu: kategori + koleksi/fandom | Catalog |
| "Produk Terbaru" + kartu berisi badge mode, nama, asal negara, harga | kartu produk = foto, judul, badge mode (PO/Siap Kirim/Drop), asal negara, harga, kustom link | Catalog |
| Produk berada di bawah **batch** (`/open-order/batch-N-...`) | batch adalah entitas kelas satu yang menaungi produk; PDP terikat periode batch, bukan cuma produk abstrak | PreOrder ⚠️ (mengubah asumsi awal) |
| `/drops` dengan aturan satu klaim per buyer per item | jalur "drop" terbatas-waktu + anti-klaim-ganda per akun | Promotion/Drop (baru) |
| Pencarian "produk, karakter, atau kategori" | satu search box memotong 3 tipe objek (faceted global search) | Search |

## 3. Halaman produk (PDP)

Elemen teramati: galeri multi-gambar dengan penghitung slide · badge mode + asal negara · blok "jadwal pemesanan/periode PO" · dimensi varian **karakter × ukuran** (dropdown `variantId`, contoh 3 karakter × 1 ukuran) · penghitung "terjual" · indikator stok dengan angka besar (stok batch = kuota, bukan fisik) · harga · qty dengan batas (input terkunci sampai varian dipilih) · CTA ganda (masuk keranjang + beli-now) · tombol favorit di PDP · blok penjelasan layanan · blok ulasan.

Temuan yang mengubah desain kita:
- **P-1 Varian dua dimensi eksplisit** (karakter × ukuran) → `variants` harus mendukung sumbu berlabel per produk, bukan kombinasi hardcoded.
- **P-2 Stok batch = kuota** (angka sangat besar untuk barang PO) → `Inventory` perlu dua semantik: stok fisik (RTS) dan kuota batch (PO) dengan aturan habis-kuota berbeda.
- **P-3 Periode PO tampil di PDP** (buka/tutup + estimasi) → `preorder_batches` (dahulu `po_batches`, Doc 11 §4.1) wajib expose window + ETA ke storefront, dan PDP mengikat ke batch aktif.
- **P-4 Ulasan gated**: kosong dengan pesan "belum ada ulasan yang **disetujui**" + "ulasan terbuka setelah pesanan selesai" → ada moderasi admin + kunci hak menulis pada order selesai. Ini fitur dua sisi (customer + antrean moderasi admin).
- **P-5 Asal negara/toko sumber** jadi field terstruktur (dipakai juga di badge) → perlu di model produk/batch, bukan di teks deskripsi.
- **P-6 "Beli sekarang"** = express checkout → butuh jalur cepat dari PDP ke checkout dengan 1 klik.

## 4. Keranjang & checkout pengiriman

| Teramati | Konsekuensi |
|---|---|
| Cart sendiri (`/dashboard/cart`), kosong → CTA ke katalog | cart tersimpan di akun, persist lintas perangkat |
| Halaman "pilih cara pengiriman" berdiri sendiri, bisa diakses tanpa barang, berisi panduan per **tier ukuran paket** (kecil/sedang/besar/sangat besar, contoh tier boneka per tinggi) + daftar "paket yang siap dipilih" | ongkir bukan hanya kurir: ada **tarif per tipe/ukuran paket** yang harus bisa diatur admin dan dijelaskan ke customer |
| Ada riwayat "kode checkout" dari marketplace pihak ketiga | jalur **pembelian-atas-nama** nyata (kini modul `MarketplacePurchase`, dahulu bernama "procurement"/D5 — Doc 11 B-4): status kode, bukti, penautan ke order kita ⚠️ fitur yang belum ada di Doc 01 |

## 5. Menu akun

### 5.1 Ringkasan (`/dashboard`)
Isi minimum: sapaan + aksi keluar. Artinya halaman ini bukan pusat kendali — semua beban ada di menu spesifik. Untuk kita: tetap worthwhile menampilkan kartu aksi (tunggakan, PO jalan, paket tiba) karena itu pemicu pembayaran; jangan salin kesederhanaannya.

### 5.2 Pesanan (`/dashboard/orders`)
3 kartu statistik (total · masih berjalan · selesai) · paginasi · tab jalur: Semua / Siap Kirim / PO / Request · filter status: dibuat, sedang diproses, siap dikirim, selesai, dibatalkan · tombol terapkan filter · empty-state mengarahkan ke katalog/request.
→ **Kesimpulan model status**: status list-nya linear & dangkal (5 nilai), tapi **segmentasi nyata ada di jalur pemenuhan** (RTS/PO/Request). Desain kita: status uang dan status pemenuhan **tidak pernah disatukan jadi satu daftar** — agar tidak perlu 15 status seperti kombinasi jalur×tahap. Perumusan awalnya "satu status uang + satu status pemenuhan + label per jalur"; pasca-Blueprint (Doc 11 **B-3**) ia menjadi **empat sumbu** (`order_status` · `payment_status` · `procurement_status` · `fulfillment_status`) dengan lane sebagai pemilih tahap — nilai kanonik di Doc 11 §8.3.

### 5.3 Request produk (`/dashboard/requests`)
Form: nama produk (wajib) · link produk · URL gambar · daftar variasi yang diinginkan (baris dinamis, tombol tambah) · jumlah (wajib) · budget per item · catatan detail (wajib, placeholder memandu: varian/warna/ukuran/negara-toko) + blok riwayat request.
→ Field kami: sama persis secara fungsi; catatan penting = **URL gambar & link produk** berarti admin dapat referensi eksternal, dan sistem harus menyimpannya mentah (tautan) bukan scraping.

### 5.4 Status barang (`/dashboard/tracking`)
Tab jalur: Semua / Siap Kirim Indonesia / Pre-order (PO) / Request Produk, dengan pernyataan bahwa tiap jalur punya tahapan sendiri dan barang lokal tidak melewati perjalanan luar negeri.
→ Kita butuh **tracking lane model** (4 lane × N tahap) bukan satu mesin status; tahap contoh per lane akan didesain di spec, dan tiap tahap harus punya sumber data (manual admin atau API kurir).

### 5.5 Invoice & tagihan (`/dashboard/invoices`)
Disebut "pusat penagihan". Kartu: total invoice · perlu dibayar (dengan aksi cek jatuh tempo) · sudah lunas. Filter tipe: Semua / **DP & pelunasan (harga barang)** / **pajak & pos luar negeri (khusus PO dan Request)** / **ongkir domestik**.
→ Temuan paling berdampak: **satu order bisa punya banyak invoice berbeda-jenis dan berbeda-jatuh-tempo**, termasuk biaya leg-internasional (pajak + pengiriman luar negeri) yang ditagih terpisah setelah barang bergerak. Ini mengubah model uang kita (lihat `01` §5 → di-amandemen oleh ADR-002).

### 5.6 Favorit
Daftar tersimpan di akun, tetap ada setelah login; ada dari PDP; kosong → CTA cari produk. → Wishlist per akun, sinkron lintas perangkat (bukan localStorage).

### 5.7 Profil
Enam grup: informasi akun · ganti password · **bahasa tampilan** (ID/EN) · keamanan perangkat (keluar semua perangkat) · data diri (nama, email, WhatsApp, avatar **dan banner** yang bisa di-upload).
→ Kebutuhan baru untuk kita: (a) **i18n sejak Sprint 0** (bukan pasca-launch), (b) manajemen sesi/perangkat, (c) profil media (avatar+banner) = aset ter-upload user yang harus di-re-encode, (d) nomor WhatsApp sebagai field akun (dipakai notifikasi & kurir).

**Keputusan 2026-11-18 (bukan observasi):** manajemen sesi/perangkat yang teramati diwujudkan lewat tabel `user_sessions` (`session_hash`, ip, `user_agent`, `device_label`, `last_seen_at`, `revoked_at` — Doc 09 §2): layar "keamanan perangkat" = daftar sesi aktif + keluar per perangkat + keluar semua perangkat (revoke semua baris sesi user itu). Transportnya cookie sesi Sanctum `elzara_web_session` (guard `web`, `Secure; HttpOnly; SameSite=Lax; Path=/`), bukan token di browser; `personal_access_tokens` tidak dibuat (ADR-007 (BFF/sesi) amandemen 2026-11-18). Alur lengkap login/logout/reset/sesi ada di Doc 02 §10.8.

## 6. Auth & konfigurasi yang terlihat dari sisi customer
- Login email+password, toggle lihat-password, lupa password, register, dan **login sosial (X/Twitter) yang dinonaktifkan dengan pesan "belum diaktifkan admin"** → ada **feature flag per-provider yang diatur dari admin**, bukan dari kode.
- Guest bisa melihat katalog/drops/PDP; keranjang, favorit, akun → butuh login.

**Keputusan 2026-11-18 (bukan observasi — Doc 11 §4.4 A/C; ADR-012 (RBAC/identitas/dependensi); ADR-007 (BFF/sesi)):**

- **Hybrid untuk login DAN registrasi:** email+password, Google (`laravel/socialite` driver bawaan), dan X (`socialiteproviders/twitter`, **OAuth 2.0** — driver `twitter` bawaan Socialite memakai OAuth 1.0a yang sudah ditinggalkan X, tidak dipasang). Catatan jujur: observasi hanya melihat tombol X/Twitter yang dinonaktifkan; **Google tidak teramati** di situs sumber — penambahannya adalah keputusan, bukan fakta observasi. Jalur callback sosial ditambahkan ke spec (Doc 11 §4.4 I), bukan rute yang teramati di §1.
- **Identitas sosial & penggabungan akun:** disimpan di `user_identities (provider, provider_user_id, user_id)`, unik per provider (Doc 09 §2). Penggabungan akun hanya lewat **email terverifikasi**, bukan klaim email mentah dari provider — kalau tidak, siapa pun yang mendaftar di provider dengan email korban bisa mengambil alih akun. Email akun baru ditandai verified hanya bila provider mengklaim verified **dan** alur verifikasi kita sendiri sukses; konsekuensinya registrasi sosial tidak instan-verified. Alur lengkap di Doc 02 §10.8.
- **Feature flag per-provider tetap ada** (sesuai observasi di atas), tetapi hanya berlaku di storefront. Login admin selamanya email+password+2FA wajib; tidak ada jalur sosial ke admin.
- **Pemisahan customer/staff tidak lagi lewat kolom populasi:** satu tabel `users`; kolom `kind` lama dihapus, diganti `is_operator` (gerbang login admin) dan `is_admin` (bypass RBAC admin berjejak audit). `is_operator`/`is_admin` tidak memberi hak apa pun di storefront — otorisasi web adalah kepemilikan (policy owner) + status akun, dan operator yang berbelanja adalah aktor `web` (`X-Actor-Type: web | admin | system`, diisi server). Customer tidak bisa mencapai admin dashboard lewat sesi storefront; masuk admin wajib login terpisah di guard `admin` dengan 2FA (Doc 06 §1.1).

## 7. Asisten AI ("Snowe …")
Panel mengambang dengan input bebas + 3 saran siap pakai bertipe akun: posisi pesanan saya, tagihan belum lunas, status pengiriman.
→ Fitur customer-facing yang menuntut: konteks akun (order/invoice/tracking) ke LLM, guard privasi (hanya data milik user itu), rate limit, dan konfigurasi admin (aktif/nonaktif, prompt, provider, biaya). Masuk backlog dengan nilai tinggi tapi bukan M1.

## 8. Konten statis = daftar halaman yang wajib bisa dikelola admin
Cara belanja (7 sub-bagian), FAQ (6 topik), kebijakan pengiriman (8), refund & pembatalan (8), retur (7), kontak (kanal resmi + keamanan komunikasi), tentang kami + transparansi transaksi.
→ Ini bukan "halaman sekali tulis": strukturnya menyerukan **page builder berbasis blok** + template kebijakan + versi/arsip dokumen hukum (karena perubahan T&C harus bisa dilacak per tanggal persetujuan customer). Teks final adalah milik klien kita, bukan salinan.

## 9. Gap observasi (tidak bisa/ tidak akan saya sentuh)
1. Isi tabel order/invoice/tracking yang sebenarnya (akun kosong) → digantikan dengan desain state machine eksplisit di spec.
2. Layar pembayaran & metode bayar (butuh membuat order) — sengaja tidak dilakukan.
3. Semua layar admin (di luar hak akses akun customer) → kebutuhan admin diturunkan dari sisi customer di Doc 06.
4. Perilaku saat batch tutup/penuh, dan apa yang terjadi pada cart berisi item PO kedaluwarsa.
Keempatnya saya tandai sebagai pertanyaan terbuka, bukan asumsi.
