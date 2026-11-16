# Dokumen 02 — Flow Bisnis & Fitur (POV Customer)

Tanggal: 2026-11-16
Basis: model bisnis hibrida RTS + Pre-order + Request Order, satu penjual, bahasa ID, IDR.
Catatan: dokumen ini mendeskripsikan **alur dan fitur generik** kategori toko merchandise impor. Tidak ada teks, gambar, atau halaman milik pihak lain yang direplikasi; copy final adalah konten klien.

## 1. Persona

| Persona | Kebutuhan utama | Titik cemas |
|---|---|---|
| **P1 Kolektor** (fans fandom tertentu, beli 1–5 item per batch) | kepastian kapan barang tiba, varian yang benar (anggota/tipe kartu), histori PO | "sudah bayar deposit, barangnya kapan?" |
| **P2 Pembeli impulsif/dropship kecil** | checkout cepat, ongkir jelas, resi aktif | "pesanan saya dibaca atau tidak?" |
| **P3 Pengaju Request** (barang langka/tiada di katalog) | bisa minta penawaran, harga transparan, tidak dilihat orang lain | "permintaan saya dijawab atau hilang?" |
| **A1 Admin merchandiser** | bikin batch PO, atur harga & varian, tutup batch | stok & ETA tidak pasti |
| **A2 Warehouse/packing** | daftar paket per batch, label, input resi massal | order campur RTS/PO |
| **A3 CS** | status pesanan + timeline + chat, refund | komplain telat baca |
| **A4 Finance** | rekonsiliasi gateway, laporan deposit vs pelunasan, payout refund | selisih settlement |

## 2. Journey utama (happy path)

### 2.1 RTS — Ready to Ship
```
Temukan produk (search/koleksi fandom/rekomendasi) 
→ PDP: foto, varian, stok "ready", ETA kirim 1–2 hari kerja 
→ Add to cart → cart drawer: subtotal, ongkir estimate 
→ Checkout: login/guest → alamat (pin/pilih provinsi-kota-kecamatan) → kurir + tarif real → metode bayar (QRIS/VA/e-wallet) → review 
→ Bayar dalam 2 jam → intent captured 
→ Order "paid" → warehouse pick-pack → label + resi 
→ "shipped" (email+WA berisi resi) → tracking timeline → delivered → completed 
→ pasca: permintaan review + poin loyalitas
```
Kontrak ke customer: harga final di review (termasuk ongkir + diskon), resi masuk <24 jam kerja setelah bayar, halaman "Pesanan Saya" selalu bisa membuka status sendiri tanpa menghubungi CS.

### 2.2 Pre-order (deposit → pelunasan → tiba → kirim)
```
PDP bertanda "Pre-order" + badge periode (buka s/d X) + ETA estimasi (mis. 6–8 minggu) 
→ Add to cart (reservasi slot batch, bukan stok fisik) 
→ Checkout: pilih bayar deposit (persentase/flat dari batch) 
→ Deposit captured → status "paid" + "menunggu batch" 
→ Pengingat H-7 dan H-1 sebelum jatuh tempo pelunasan (email+WA, tautan bayar langsung) 
→ Pelunasan captured → status tetap "menunggu batch" 
→ Batch tiba (admin GRN) → **per buyer** yang kewajibannya tuntas: sub-order jadi "ready to ship" (lihat F5/F6 di §2.5) → notif "barang tiba" 
→ Label + resi → shipped → delivered → completed
```
Aturan bisnis yang harus diputuskan klien sebelum Sprint 4 (nilai default kami usulkan):
- **B-PO-1** Besar deposit: % (default 30 %) atau flat per varian.
- **B-PO-2** Jatuh tempo pelunasan: default 7 hari setelah barang tiba, bukan setelah batch ditutup (karena customer tidak bisa memverifikasi kedatangan).
- **B-PO-2b** Lebar toleransi sebelum `on_hold`: 3 hari; sebelum `expired`: 7 hari.
- **B-PO-3** Nasib deposit kalau telat/expired: default hangus (perlu ditulis di T&C dan muncul 2× saat checkout: di cart dan di checkbox konfirmasi).
- **B-PO-4** Deposit refundable bila batch batal karena supplier: default refund penuh ke metode asal atau voucher 110 % (pilihan customer).
- **B-PO-5** Boleh batalkan PO oleh customer sebelum batch ditutup? Default: tidak, hanya refund deposit sebagai voucher.
- **B-PO-6** Minimum order per batch, batas qty per akun (anti-tengkul): default 6 pcs/varian.
- **B-PO-7** Gabung ongkir RTS+PO dalam satu paket: kirim terpisah per jalur (RTS lebih dulu) dan ongkir dihitung per shipment. Alasannya: menggabungkan dua tagihan ke customer lebih buruk daripada memecah paket.

### 2.4 Delta bisnis pasca-observasi (bukti: `05-customer-feature-inventory.md`, keputusan: `adr/ADR-002`)

**Model tagihan.** Satu order dapat memancarkan beberapa tagihan dengan jatuh tempo sendiri, bukan satu harga final di muka:

| Tipe tagihan | Kapan nominal pasti | Contoh pemicu |
|---|---|---|
| `deposit` | saat order dibuat (dari aturan batch) | PO dibuka |
| `balance` | saat order dibuat | harga barang dikurangi deposit |
| `intl_fees` | **setelah barang bergerak/GRN** | pajak impor + biaya pos internasional leg luar negeri; ditampilkan sebagai estimasi + batas atas saat PO, ditagih nyata saat tiba |
| `domestic_ship` | saat order/pengiriman | ongkir lokal per shipment |
| `extra_ship` | insidental | kirim ulang setelah retur/alamat salah |
| `adjustment` | manual admin beralasan | koreksi lebih bayar, goodwill |

Dampak ke customer journey: halaman "tagihan saya" menjadi pusat aksi (bayar, cek jatuh tempo, lihat rincian per tipe), dan komunikasi reminder harus menyebut tipe tagihan — "pelunasan barang" berbeda nadanya dengan "biaya pengiriman internasional".

**Lane tracking.** Status perjalanan barang mengikuti empat jalur berbeda: lokal siap-kirim, impor PO, request (menyusul PO atau lokal), dan procurement atas nama buyer. Setiap jalur punya tahap sendiri, jadi tidak ada janji "sama seperti pesanan biasa" di copy.

**Drop & klaim.** Barang tertentu dijual sebagai drop dengan aturan satu klaim per akun per item → perlu aturan anti-klaim-ganda di server, dan UI yang menjelaskan nasib klaim setelah kedaluwarsa.

**Ulasan.** Hak menulis ulasan muncul setelah pesanan selesai, dan ulasan tampil hanya setelah disetujui admin. Dua sisi ini harus konsisten: customer perlu tahu mengapa ulasannya belum muncul.

**i18n.** Bahasa tampilan menjadi bagian akun (pilihan ID/EN tersimpan di profil), bukan fitur pasca-launch. Ini mengubah cara kita menulis copy: semua string lewat resource, termasuk email dan halaman kebijakan.

**Keputusan tambahan untuk klien:** B-PO-8 apakah biaya internasional boleh ditagih tanpa konfirmasi customer (default: ya, dalam batas atas yang diumumkan saat PO) · B-PO-9 apakah jalur procurement atas-nama dipakai di toko klien (kalau tidak, modulnya dimatikan) · B-PO-10 ambang nilai refund yang butuh persetujuan kedua · B-PO-11 drop: first-come atau lotre.

### 2.3 Request Order (kuotasi privat)
```
Customer isi form: nama barang, referensi (foto/link), qty, target harga, catatan, RTS/PO netral 
→ "Diajukan", status "meninjau" (SLA jawaban: 2 hari kerja) 
→ Admin bikin penawaran: harga per unit, qty bisa dipenuhi, mode RTS/PO, biaya kirim, berlaku s/d 
→ Customer dapat notif "penawaran siap" → melihat hanya miliknya (URL privat, tidak di katalog/search/sitemap) 
→ "Ambil penawaran" → cart terisi dari quote → bayar intent 
→ atau "Lewati" / kedaluwarsa → stok terkunci dilepas, pengajuan tercatat "tidak lanjut" 
→ riwayat pengajuan per akun (customer bisa lihat "saya pernah minta apa")
```
Ekspektasi yang harus dikelola di UI: penawaran bukan harga final ongkir (ongkir dihitung saat checkout), dan masa berlaku penawaran adalah harga + stok, bukan hak beli selamanya.

### 2.5 Aturan operasional yang terkonfirmasi (bukti: `07-business-profile-reference.md`)

Rule F1–F11 di dokumen itu sudah dirangkum; enam di antaranya langsung jadi aturan bisnis produk kita:

- **RS-1 Gerbang "siap kirim" (F4/F6).** Sub-order boleh pindah ke `ready_to_ship` hanya bila tiga hal benar sekaligus: barang tersedia (GRN untuk impor / stok untuk lokal), **seluruh invoice wajib** bertipe `balance`/`intl_fees`/`domestic_ship` berstatus lunas, dan packing selesai. Ini gerbang AND, bukan kolom status tunggal — dan wajib punya test sendiri.
- **RS-2 Batch maju per buyer (F5).** Buyer yang belum melunasi tidak menahan buyer lain dalam batch yang sama. Notifikasi "barang tiba" dan tombol bayar pelunasan dikirim per akun, dan pelepasan batch bersifat parsial. Ini mengoreksi rancangan lama yang melepaskan batch serempak.
- **RS-3 Tagihan impor hanya untuk lane impor (F3).** Ready stock tidak boleh memunculkan invoice TAX/EMS. Gabungan TAX/EMS boleh jadi satu invoice dengan dua sub-rincian agar bisa dijelaskan ke customer.
- **RS-4 Harga punya masa berlaku (F9).** Stok, kurs, dan biaya dapat berubah sebelum pembelian dikonfirmasi. Aturan kita: harga dikunci pada saat order dibuat; untuk invoice yang terbit belakangan (`intl_fees`) dan untuk penawaran request, tampilkan estimasi + batas atas + tombol "saya setujui nominal ini" sebelum bayar, dan larang pembayaran otomatis atas nominal yang naik melebihi ambang (default ±5 % → butuh re-konsen).
- **RS-5 Kode procurement = satu paket, satu kali pakai (F7/F8).** Jalur beli atas nama marketplace pihak ketiga adalah jalur reguler: admin menerbitkan kode → buyer memakai saat checkout di platform itu → buyer melapor di website kita → admin memverifikasi → tracking dilanjutkan di platform asal. Kode tidak valid lagi setelah terverifikasi dan tidak bisa dipakai untuk paket kedua.
- **RS-6 Laporan pembayaran ganda (F11).** Sediakan form "sudah bayar tapi belum lunas / terpotong dua kali" yang meminta nomor invoice + referensi transaksi, masuk ke antrean Finance. Jangan pernah menampilkan tombol "bayar lagi" tanpa peringatan bahwa sistem sedang menunggu konfirmasi gateway.

**Keputusan tambahan untuk klien:** B-PO-12 ambang kenaikan nominal `intl_fees` yang memaksa re-konsen · B-PO-13 dasar hitung TAX/EMS (riil per berat/pajak atau flat per batch) · B-PO-14 apakah jalur RS-5 dipakai di toko klien (kalau ya, masuk MVP; kalau tidak, flag mati tapi desain tetap ada).

## 3. Fitur per area — POV customer

Legenda prioritas: **M1** wajib MVP · **M2** harus ada sebelum launch · **M3** pasca-launch · **M4** opsional/permintaan klien.

### 3.1 Discovery & katalog
| Fitur | Prio | Detail & acceptance |
|---|---|---|
| Search typo-tolerant + sinonim fandom | M1 | hasil <300 ms, empty-state menawarkan kategori terdekat |
| Filter facet: kategori, koleksi/IP, tipe barang, harga, mode (RTS/PO), status stok, rating | M1 | kombinasi filter bisa di-share lewat URL |
| Kategori & koleksi = data, lengkap dengan ikon terupload | M1 | 8 nilai kategori teramati di shell publik situs sumber (Doc 08 §5); slug kategori stabil karena dipakai di URL filter `?category=`; tiap kategori punya ikon + `media.alt` wajib (gerbang aksesibilitas Doc 04 §7) |
| Navigasi utama dari data (admin bisa susun menu tanpa deploy) | M2 | menu = `nav_items`, cache invalidation otomatis |
| Halaman koleksi/fandom (banner + grid + deskripsi) | M1 | dibuat admin dari modul Content |
| Badge mode pada kartu produk ("Pre-order", "Ready", "Limited") + ETA | M1 | badge terbaca tanpa membuka PDP |
| Countdown periode PO pada kartu & PDP | M2 | pakai waktu server, bukan jam browser |
| Wishlist (login) / favorit persist | M2 | guest → dipromosikan login saat pindah akun |
| Riwayat dilihat ("baru dilihat") | M3 | guest via cookie, 20 item |
| Rekomendasi (sering dibeli bareng / varian lain) | M3 | aturan sederhana dulu, bukan ML |
| Pagination + infinite di grid; lazy image; LCP <2,5 s 4G | M1 | audit performa jadi gate Sprint 7 |

### 3.2 Halaman produk (PDP)
Gallery zoom + video singkat · varian bertingkat (mis. ukuran → anggota → tipe kartu) dengan harga & stok per varian · pilih qty dengan batas batch · info mode & ETA · blok "cara PO" inline (dari Content, bukan hardcode) · total estimasi (harga × qty) sebelum masuk cart · status "habis" dengan tombol "kabari saya" (M3) · catatan kondisi/impor (mis. "box bisa penyokminor, bukan komplain") · syarat retur per kategori (kartu/photocard = tidak bisa retur kalau segel dibuka) · review & rating (M2) · FAQ per kategori (M3) · share link.

Acceptance kunci: mengganti varian mengubah harga, stok, badge, dan foto sekaligus, tanpa reload penuh; harga tidak pernah tampil dua angka berbeda di PDP dan cart.

### 3.3 Cart & checkout
Cart drawer + halaman cart · pengelompokan baris per mode (RTS vs per batch PO) dengan ETA masing-masing · edit qty dari cart dengan validasi batas batch/stok · hapus & "pindahkan ke wishlist" · input voucher dengan pesan alasan kalau tolak (expired/min/kategori) · poin loyalitas terpotong (M2) · ongkir multi-kurir dengan perbandingan tarif & estimasi tanggal (M1, driver Manual menampilkan tarif admin) · alamat: saved addresses, pinpoint/map (M2), validasi kodepos-wilayah, nomor HP untuk kurir · metode bayar per driver (QRIS, VA per bank, e-wallet, manual transfer) + batas waktu bayar terlihat countdown · ringkasan final: subtotal, diskon, ongkir, biaya layanan, total · checkbox T&C + checkbox khusus PO/deposit hangus · guest checkout boleh (M2) dengan klaim order lewat email+nomor · review order sebelum bayar · invois PDF (M2) · retainer: kembali ke cart utuh setelah gagal bayar.

### 3.4 Akun & pesanan saya
Login email+password / magic link atau OTP (M2), reset password, social login (M3) · profil + alamat (utama/alternat, label rumah/kantor) · daftar pesanan dengan filter status + mode · detail order: timeline visual per event (dibayar → menunggu batch → tiba → dikirim → delivered), barang per shipment, invoice + bukti bayar, tombol bayar pelunasan, input/no resi + link tracking, tombol batalkan (selama kebijakan memperbolehkan), ajukan retur/refund dengan foto · riwayat Request Order & penawaran · wishlist · notifikasi preferensi (email/WA/none per event) · unduh data & hapus akun (M3).

### 3.5 Tracking & komunikasi
Timeline per shipment dengan event kurir (label dibuat, in transit, out for delivery, delivered, exception) · fallback kalau API tracking mati: status manual admin + catatan "info terakhir …" · notifikasi event: order dibuat, deposit diterima, tagihan pelunasan (H-7/H-1/jatuh tempo), batch ditutup, barang tiba, dikirim + resi, attempted delivery gagal, delivered, refund diproses/ditolak, penawaran diterbitkan/kedaluwarsa, stok wishlist kembali (M3) · kanal: email wajib, WA untuk status uang & pengiriman, deep-link ke halaman order.

Matriks notifikasi (kolom "owner copy" = klien):
| Event | Email | WA | In-app | Trigger |
|---|---|---|---|---|
| Order dibuat (belum bayar) | ✔ | – | ✔ | PlaceOrder |
| Deposit/lunas diterima | ✔ | ✔ | ✔ | PaymentCaptured |
| Tagihan pelunasan | ✔ | ✔ | ✔ | scheduler H-7/H-1/due |
| Batch ditutup | ✔ | – | ✔ | PoBatchClosed |
| Barang tiba, siap kirim | ✔ | ✔ | ✔ | PoBatchArrived |
| Dikirim + resi | ✔ | ✔ | ✔ | ParcelShipped |
| Exception kurir >3 hari | ✔ | ✔ | – | TrackingUpdated |
| Refund diproses | ✔ | ✔ | ✔ | RefundApproved |
| Penawaran diterbitkan | ✔ | ✔ | ✔ | QuoteIssued |

### 3.6 Retur, refund, pembatalan
Batas ajukan: 3 hari setelah delivered (konfigurasi) · alasan + foto wajib untuk rusak/hilang/varian salah · empat outcome: kirim ulang, refund metode asal, refund voucher, ditolak + alasan · SLA respon CS 1 hari kerja · refund parsial per item didukung ledger · pembatalan: sebelum bayar = bebas, RTS sudah bayar sebelum kirim = refund dikurangi biaya gateway, PO = mengikuti B-PO-3/B-PO-5 · semua state customer-facing + internal + catatan audit.

### 3.7 Trust & konten (sering diremehkan, dampaknya konversi)
Halaman "Cara Belanja / Cara PO" (M1, dari Content) · halaman ongkir & estimasi waktu per kurir (M1) · kebijakan deposit & hangusnya (M1, ditulis + muncul saat checkout) · FAQ (M2) · profil toko & kontak/WA bisnis (M1) · review bertanda "pembeli terverifikasi" (M2) · Galeri/batch sebelumnya (M3) · blog/kabar rilis (M4) · badge keamanan pembayaran (dari gateway, bukan klaim sendiri).

### 3.8 Aksesibilitas & locale
Kontras WCAG AA, focus-visible, label form, alt untuk gambar produk, target sentuh ≥44 px, navigasi keyboard di cart/checkout, `prefers-reduced-motion` untuk countdown & animasi · format IDR `Rp1.250.000` tanpa desimal, tanggal & zona Asia/Jakarta, nomor HP format `+62` · terjemahan string berada di resource file sehingga tema/brand lain bisa menyesuaikan nada bahasa tanpa ubah komponen.

## 4. Alur pengecualian (wajib ada UI + statusnya)

| Situasi | Sistem | Customer lihat |
|---|---|---|
| Bayar tapi webhook hilang | rekonsiliasi + tombol "saya sudah bayar" → klaim manual CS | status "menunggu verifikasi pembayaran", bukan "belum bayar" |
| Bayar 2× / double capture | ledger deteksi → auto-refund kelebihan | notif refund + catatan |
| Stok berubah saat checkout (RTS laris) | harga & stok snapshot + guard `InsufficientStock` | modal "stok habis, pilih qty lain / pindah wishlist" |
| Batch molor ETA | ETA batch bisa digeser admin + notif massal per batch | ETA baru + alasan singkat |
| Batch batal (supplier gagal) | `cancelled_by_supplier`, refund massal 1 aksi | pilihan refund asal / voucher insentif |
| Sebagian item tiba (partial GRN) | shipment pecah per item tiba | "3 dari 5 item tiba, sisanya menyusul" |
| Pelunasan tidak dibayar | `on_hold` → reminder → `expired` sesuai B-PO-2b/3 | status jelas + apa yang terjadi dengan deposit |
| Paket returned ke toko (alamat salah) | status `returned_to_sender`, biaya kirim ulang | tombol "perbaiki alamat & bayar kirim ulang" |
| Retur diterima sebagian | refund per item | timeline refund per item |
| Request Order ditolak admin | keputusan + alasan, reservasi dilepas | "tidak bisa kami penuhi karena …" |
| Kurir tanpa API/berita jelek | driver Manual sebagai fallback kelas satu | status manual + last update + catatan |

## 5. Backoffice minimum agar janji customer bisa ditepati

Katalog & varian (bulk import CSV, harga per varian, foto) · batch PO (buat, ETA, deposit rules, tutup, tiba/GRN, batalkan, report posisi: deposit masuk vs pelunasan kurang) · daftar kerja warehouse (paket per batch/batch pick list, label, input resi massal + scan) · manajemen order (filter, detail, transisi dengan reason, refund) · antrian pembayaran tidak pasti (reconcile) · antrian retur & tiket CS · voucher/kampanye + poin · konten (blok homepage, banner, halaman, menu) · tarif manual per kurir/zona kalau tanpa API · laporan (penjualan, margin per varian, umur piutang pelunasan, produk hampir habis, sumber trafik) · audit & role + 2FA · export CSV.

## 6. Metrik & definisi sukses

| Metrik | Baseline target |
|---|---|
| Conversion (visit→order paid) | ≥1,5 % (RTS) / ≥0,8 % blended |
| Cart→paid (RTS) | ≥45 % |
| Deposit→pelunasan tepat waktu | ≥85 % |
| Rata-rata hari telat pelunasan | ≤2 hari |
| Waktu buat label sejak dibayar | RTS <24 jam kerja; PO <48 jam sejak tiba |
| Penawaran dijawab dalam SLA | ≥95 % ≤2 hari kerja |
| Tiket "di mana pesanan saya?" | <8 % order |
| Refund rate | <2 % |
| LCP mobile / error rate checkout | <2,5 s / <0,5 % |

## 7. Keputusan yang menunggu jawaban klien

B-PO-1 … B-PO-7 (§2.2) dan B-PO-8 … B-PO-14 (§2.4–§2.5) — tiga yang paling menentukan jadwal: **B-PO-13 dasar hitung TAX/EMS**, **B-PO-14 apakah jalur procurement atas-nama dipakai**, **B-PO-12 ambang re-konsen kenaikan nominal**. Sisanya: guest checkout ya/tidak, batas qty anti-tengkul, kebijakan deposit hangus + bunyi T&C (legal milik klien), kanal WA provider, kurir yang dipakai + kontrak tarif, siapa penanggung jawab konten/foto (harus aset klien), kebutuhan TDP/pajak/invoice resmi, dan target tanggal launch. Bahasa: EN bukan lagi opsi pasca-launch — i18n masuk Sprint 0 (§2.5, Doc 03 §10.5).

## 8. Di luar cakupan

Aplikasi mobile, multi-gudang/multi-tenant, marketplace seller mandiri, pricing dinamis, program afiliasi, COD/transfer manual tanpa gateway sebagai jalur utama, fitur sosial (komunitas/forum), dan setiap bentuk pengumpulan data atau konten dari situs pihak lain.
