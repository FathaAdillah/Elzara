# Dokumen 06 — Kebutuhan Admin Dashboard (diturunkan dari sisi customer)

Tanggal: 2026-11-16
Sumber penurunan: setiap kemampuan customer di `05-customer-feature-inventory.md` punya "beban kerja" di belakang layar. Admin dashboard = tempat beban itu diselesaikan. Ini **spesifikasi kebutuhan kami**, bukan tiruan panel siapa pun.

Batas yang berubah setelah REA pass (Doc 08 §3): dari sisi publik **bisa** dipastikan bahwa panel admin situs sumber bukan aplikasi terpisah — rute `/admin` berada di aplikasi yang sama, memakai layout global yang sama, dan memakai komponen chunk yang sama dengan halaman tracking/invoice customer (satu tabel data generik). Yang **tetap tidak bisa** diamati adalah daftar layar, field, dan alur kerja di dalamnya; karena itu dokumen ini diturunkan dari sisi customer, bukan dari observasi admin. Tidak ada upaya enumerasi sub-rute admin yang dilakukan.

## 1. Role & hak akses minimum

| Role | Cakupan | Alasan dari sisi customer |
|---|---|---|
| Owner | penuh + pengaturan bisnis + setujui kebijakan | — |
| Merchandiser | batch, produk, varian, kuota, harga, drop | PDP menampilkan periode batch, kuota, harga |
| Warehouse | pick list, GRN, label, resi massal, exception | timeline status barang butuh event nyata |
| CS | order, retur, request, tiket, refund (ajukan) | SLA jawaban penawaran & komplain |
| Finance | invoice, rekonsiliasi, settlement, refund (eksekusi) | "cek jatuh tempo" & tipe invoice |
| Editor konten | halaman kebijakan, FAQ, banner, nav, meta SEO | 9 halaman kebijakan berstruktur |
| Moderator | ulasan, foto request, lampiran user | ulasan butuh "disetujui" |
| (dev/ops) | feature flag, kredensial, job | mis. toggle login sosial |

Wajib: 2FA untuk role non-owner, `audit_logs` setiap mutasi (harga, status, refund, publish halaman kebijakan), dan pemisahan "ajukan vs setujui" untuk refund di atas ambang tertentu.

## 2. Kerangka screen (nav admin)

```
Ringkasan hari ini        | pesanan baru, uang masuk, tunggakan jatuh tempo, paket perlu resi
Katalog                   | produk · varian/matriks · media · kuota batch · harga
Batch & Pre-order         | daftar batch · buka/tutup · ETA ·GRN · posisi bayar · pembatalan
Request / Penawaran       | antrian pengajuan · buat penawaran · tolak · SLA · riwayat per akun
Penjualan (Orders)        | daftar · detail · koreksi status · pembatalan · retur/refund
Penagihan (Billing)       | invoice per tipe · jatuh tempo · reminder · rekonsiliasi gateway
Pengiriman                | metode & tarif per tier paket · label · input resi massal · exception kurir
Procurement (opsional)    | pembelian pihak ketiga atas nama buyer · status kode · bukti bayar · penautan ke order
Promosi                   | voucher · kampanye · poin · drop/klaim terbatas
Konten                    | halaman kebijakan (versi) · FAQ · banner · nav · SEO · template email
Ulasan & Media            | moderasi ulasan · moderasi lampiran · alt text
Customer                  | akun · sesi/perangkat · favorit · alamat · preferensi notifikasi
Laporan                   | penjualan, margin, umur piutang pelunasan, stok menipis, funnel checkout
Pengaturan                | brand & tema · bahasa · payment/shipping/WA driver · fitur flag · legal
```
⚠️ baris "Procurement" muncul **karena** sisi customer memperlihatkan riwayat kode checkout dari marketplace lain dan jalur "beli atas nama"; kalau model bisnis klien Anda tidak memakai jalur itu, module ini di-nonaktif-kan lewat flag — tapi jangan dihapus dari desain, karena ia mengubah flow pembayaran & tracking.

## 3. Screen-by-screen: field, aksi, dan alasan

### 3.1 Batch & Pre-order (inti)
Buat batch: nama/kode, periode buka-tutup, ETA estimasi (tanggal + toleransi hari), status (`draft/open/closed/arrived/cancelled`), deposit rule (% atau flat, min/max), jatuh tempo pelunasan (default N hari **setelah barang tiba**), kebijakan deposit saat telat (hangus/voucher/perpanjangan manual), batas qty per akun, asal negara/toko sumber (dipakai badge PDP).
Aksi per batch: tutup paksa · geser ETA (memicu notif massal ke anggota batch) · tandai tiba/GRN per item (parsial) · batalkan karena supplier (refund massal + pilih metode) · export posisi bayar.
Data yang harus tampil: jumlah unit vs kuota, uang masuk (DP) vs piutang pelunasan, jumlah akun, item yang belum di-GRN, order `on_hold`.
Why dari sisi customer: PDP men-show periode PO + kuota, halaman tracking memisah lane PO, tagihan memisah DP/pelunasan/pajak-EMS.

### 3.2 Katalog & varian
Produk: nama, deskripsi (blocks), kategori + koleksi/fandom (2 sumbu), asal negara, media (gallery berurutan + alt text), status publish, mode pemenuhan (RTS/PO/drop).
Varian: matriks berdimensi (mis. karakter × ukuran) dengan harga, kuota, stok fisik, SKU, media per varian, dan status aktif — karena PDP memaksa "pilih varian dan ukuran terlebih dahulu" sebelum qty bisa diisi.
Stok: dua kolom terpisah (`physical_qty` RTS, `batch_quota` PO) + ledger pergerakan + alert minimum.
Bulk: import CSV/XLSX (nama, varian, kuota, harga, gambar per folder), dan "salin produk ke batch baru".

### 3.3 Request / Penawaran
Antrian: pengajuan masuk + status (baru, ditinjau, penawaran diterbitkan, diambil, kedaluwarsa, ditolak) + SLA timer + filter per akun.
Buat penawaran: per baris item harga unit, qty bisa dipenuhi, mode (RTS/PO + batch mana), masa berlaku, catatan internal, biaya kirim diingatkan dihitung saat checkout.
Aksi: terbitkan (→ notif ke akun itu saja) · perbaiki setelah dinegosiasi · tolak dengan alasan (tersimpan di riwayat customer) · klaim stok (reservasi) & lepas otomatis saat kedaluwarsa.
Kontrol penting: visibilitas privat ditegakkan di server (policy), admin bisa melihat semua, customer hanya miliknya.

### 3.4 Penagihan (Billing) — layar yang paling berubah akibat temuan
Model: **invoice = 1 tagihan ber-jatuh-tempo**, order = 1 kejadian. Satu order PO bisa memancarkan 3–4 invoice: DP harga barang, pelunasan harga barang, pajak+pos internasional, ongkir domestik (dan ongkir ulang bila retur).
Screen: daftar invoice (filter tipe/status/akun/batch) · detail (nominal, jatuh tempo, kanal bayar, riwayat percobaan, reminder terkirim) · aksi "tagih manual" (buat invoice tambahan dengan alasan) · "tandai lunas manual" (dengan bukti + double-approval) · "batal tagih" · "rekonsiliasi" (selisih settlement vs ledger) · laporan umur piutang (H+, 7, 14, 30).
Konsekuensi arsitektur: lihat ADR-002 (amandemen model uang di `01` §5).

### 3.5 Pengiriman
Kelola metode: kurir/layanan, tipe paket (tier ukuran), tarif (per zona/berat/tier), aktif/nonaktif, waktu estimasi; mode "panduan saja" (customer memilih, admin hitung manual) sebagai warga kelas satu.
Operasi: daftar paket siap pilih per batch, cetak label, **input resi massal (CSV/paste)**, koreksi resi, tandai exception (`returned_to_sender`, tertahan >N hari), trigger notif per tahap lane.
Lane tracking: editor tahap per jalur (RTS lokal, PO impor, request, procurement) dengan sumber data tiap tahap (API kurir / manual) — karena halaman status customer menampilkan tahapan berbeda per jalur.

### 3.6 Procurement (jalur beli-atas-nama)
Siklus hidup kode (aturan RS-5 di Doc 02): `issued → used_by_buyer → reported → verified → linked_to_shipment`, dan `expired/revoked` sebagai cabang. Penegakan di server: **satu kode untuk satu paket**, tidak bisa dipakai ulang setelah terverifikasi, dan laporan buyer wajib disertai nomor order/paket yang cocok sebelum admin bisa memverifikasi.
Screen: daftar kode per batch/order · terbitkan kode (nominal, marketplace, batas pakai) · antrian "dilaporkan buyer" (bukti + nomor pesanan pihak ketiga) · verifikasi (role Finance/CS dengan alasan) · tautkan ke shipment lane procurement · unggah bukti bayar & resi awal · status "lacak dilakukan di platform asal" dengan tautan + tanggal lapor.
Regulatoris: bukti dan nominal disimpan untuk rekonsiliasi & sengketa, tidak pernah dipublikasikan ke akun lain. Kalau klien tidak memakai jalur ini, flag dimatikan — tapi layar dan aturannya tetap ada di desain.

### 3.7 Promosi & Drop
Voucher (per tipe, periode, kuota, min belanja, per kategori/koleksi/produk, per akun) · poin & tier + ledger poin · **Drop**: jadwal buka, item, batas 1 klaim per akun per item, antrian/lotre kalau diminta, dan pembatalan massal. (Aturan klaim satu-per-item terlihat dari sisi customer dan butuh enforcement di server + laporan klaim.)

### 3.8 Konten
Page editor per blok dengan **versi + tanggal berlaku** (kebijakan T&C berubah → kita harus tahu customer menyetujui versi mana saat checkout), template FAQ, banner carousel (slide, link, urutan, aktif), nav editor, SEO/OG per halaman, redirect. Semua teks ditulis klien kita sendiri.

### 3.9 Ulasan & moderasi media
Antrean ulasan (hanya dari akun dengan order selesai) → approve/reject dengan alasan, tampilkan nama/akun yang boleh disamarkan, moderasi lampiran (foto request/banner user), alt text wajib sebelum publish.

### 3.10 Customer & sesi
Cari akun (email/WhatsApp) · riwayat order/invoice/request/favorit dalam satu profil (360° view untuk CS) · blokir/perpanjang jatuh tempo · keluar semua perangkat atas nama user · preferensi notifikasi · ekspor & penghapusan data pribadi (hak user) dengan catatan legal-hold untuk transaksi.

### 3.11 Pengaturan & feature flag
Brand (nama, logo, legal, kontak) · tema aktif · bahasa aktif (ID wajib, EN dari awal) · payment driver + credential + mode sandbox/live + **toggle metode per gateway** · shipping driver · WA/email driver · **toggle login sosial per provider** (persis pola "belum diaktifkan admin" di sisi customer) · batas qty/anti-tengkul · maintenance mode per area.

## 4. Matriks "janji customer → layar admin"

| Janji ke customer | Tanpa layar ini, janji tidak bisa ditepati |
|---|---|
| "PO, estimasi tiba X minggu" | batch ETA + notif saat ETA bergeser |
| "Deposit lalu lunas" | invoice engine + reminder jatuh tempo |
| "Tagihan pajak & pos luar negeri terpisah" | tagih manual beralasan + tipe invoice |
| "Cek status 3 jalur" | lane tracking editor + event per tahap |
| "Resi muncul setelah dikirim" | input resi massal + API kurir |
| "Penawaran request dijawab ≤2 hari" | antrian SLA + reminder escalation |
| "Ulasan hanya pembeli terverifikasi" | moderasi + validasi order selesai |
| "Satu klaim per item di drop" | aturan klaim + laporan |
| "Pilihan ongkir per ukuran paket" | tarif tier paket + mode panduan |
| "Barang tidak sesuai bisa retur" | workflow retur + refund partial |
| "Dijawab CS cepat" | tiket + 360° profil |

## 5. Prioritas pembangunan admin (sinkron dengan sprint)

| Sprint | Layar admin |
|---|---|
| 0 | kerangka + auth role + 2FA + audit + flag |
| 1 | katalog (produk/varian/media/kuota) + konten dasar |
| 2 | order masuk (baca) + penagihan dasar (intent) + flag payment |
| 3 | penjualan penuh + pengiriman (metode/tarif/resi) + tracking lane + notifikasi + retur + tiket |
| 4 | batch & PO penuh + billing multi-invoice + laporan posisi bayar |
| 5 | request/penawaran + SLA + visibilitas |
| 6 | promosi/drop + konten (versi T&C) + moderasi ulasan |
| 7 | laporan, rekonsiliasi lanjutan, ekspor, UAT |

## 6. Pertanyaan yang perlu dijawab klien sebelum spec admin final

Apakah jalur beli-atas-nama (marketplace pihak ketiga) dipakai di toko klien kita? Siapa yang boleh menyetujui "tandai lunas manual"? Apakah drop perlu lotre/antrian atau cukup first-come? Berapa ambang refund yang butuh persetujuan ganda? Apakah pajak/pos internasional ditagih otomatis setelah GRN atau manual per batch? Bahasa mana yang wajib siap saat launch?
