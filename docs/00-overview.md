# Elzara — Re-engineering Platform E-commerce Merchandise Impor

Dokumen indeks & kesepakatan awal. Baca ini lebih dulu.
Tanggal: 2026-11-16 (diperbarui 2026-11-17) · Status: **diterima untuk eksekusi** — jalur penuh ±18 minggu (Doc 03 §10.5 opsi a) dipilih; asumsi bisnis pada §"Asumsi yang belum dikonfirmasi" masih menunggu jawaban klien dan berjalan paralel dengan Sprint 0.

## Isi

| Berkas | Isi |
|---|---|
| `01-architecture.md` | Topologi modular monolith + jalur microservice, 16 modul & kepemilikan data, model uang, state machine, keamanan, Docker/infra, observability, testing, risiko |
| `02-business-flow-customer-features.md` | Persona, journey RTS/PO/Request Order, daftar fitur POV customer dengan prioritas M1–M4, alur pengecualian, matriks notifikasi, backoffice minimum, metrik |
| `03-sprint-plan.md` | Sprint 0–7 (±1 sprint tambahan akibat temuan), task & estimasi, Definition of Done, jalur paralel klien, risiko jadwal, urutan potongan, backlog pasca-launch |
| `04-frontend-theme-guide.md` | Token Tailwind v4 3 lapis, `config/brand.php`, 3 preset tema orisinal, inventaris komponen + slot branding, aksesibilitas, performa, checklist ganti brand |
| `adr/ADR-001-topologi.md` | Record keputusan topologi A vs B vs C |
| `05-customer-feature-inventory.md` | Inventaris fitur per menu hasil observasi akun customer (route, PDP, checkout, 7 menu akun, auth, asisten AI, konten) + delta yang mengubah arsitektur |
| `06-admin-dashboard-requirements.md` | Kebutuhan admin dashboard yang diturunkan dari janji customer: role, screen, field, aksi, dan prioritas per sprint |
| `adr/ADR-002-invoice-model.md` | Amandemen model uang: `orders → invoices → payment_intents`, tipe tagihan & jatuh tempo terpisah |
| `07-business-profile-reference.md` | Profil bisnis & aturan flow yang diumumkan publik (identitas, 3 jalur layanan, F1–F11) + apa yang tidak diungkap |
| `08-site-structure-inventory.md` | Hasil reverse engineering **struktural** situs referensi memakai REA: stack teridentifikasi, 20 rute terverifikasi, keluarga komponen (termasuk fakta admin = satu aplikasi), permukaan API, taksonomi publik, baseline header keamanan, dan batas metode |
| `09-database-schema.md` | Skema fisik Postgres 16: 85 tabel + view pelaporan, keputusan desain S-1…S-10 (uang integer, enum varchar+CHECK, tabel append-only, snapshot), notasi baca, kepemilikan per modul, dan daftar yang sengaja tidak ada |
| `10-migration-plan.md` | Cara skema 09 dibangun: urutan level tabel (Appendix A), penomoran & kepemilikan file migrasi, aturan anti-tabrakan untuk 1–2 dev, expand–backfill–contract zero-downtime, seeder reference vs demo, rollback/backup, dan 8 pemeriksaan CI |

## Keputusan yang sudah disepakati

1. Topologi: **modular monolith (Opsi A)** dengan batas modul tegas + outbox sejak awal, dan jalur ekstraksi terdokumentasi ke **microservice-lite (Opsi B)**; Opsi C (8–10 service) ditolak.
2. Backend: **PHP 8.4 / Laravel**, queue Horizon, Redis, Postgres 16, Meilisearch, MinIO.
3. Frontend: **Inertia + React + Tailwind v4**, tema berbasis token sehingga branding klien tinggal diganti.
4. Payment & shipping: **adapter multi-driver** (`PaymentProvider`, `ShippingRateProvider`, `TrackingProvider`) — satu driver aktif di MVP, driver tambahan masuk lewat contract test.
5. Hosting: **VPS + Docker Compose** (profil dev/staging/prod), TLS di edge, backup terjadwal.
6. Model bisnis: tiga jalur — Ready to Ship, Pre-order (deposit → pelunasan → barang tiba → kirim), Request Order (kuotasi privat per akun).
7. Tim & tempo: 1–2 dev. Setelah temuan observasi (Doc 05/06/07 + ADR-002) jalur kritis menjadi **±18 minggu** (+buffer 2 sprint); keempat fitur khas masuk MVP dengan dampak dirinci di Doc 03 §10.5. **Diputuskan 2026-11-17: opsi (a) — 18 minggu, semua fitur masuk MVP.** Drop & moderasi ulasan (opsi b) dan jalur procurement (opsi c) tetap di backlog bila nanti jadwal needing potong, dengan urutan pemotongan Doc 03 §11.

## Metode observasi kategori ini

Sebagian asumsi awal dikoreksi lewat peninjauan langsung pada situs referensi **menggunakan akun customer milik pemilik proyek** (2026-11-16), secara **hanya-baca**: tanpa membuat order, tanpa transaksi pembayaran, tanpa mengubah/menghapus data, dan tanpa upaya mengakses panel admin. Hasilnya adalah daftar kemampuan per menu di Doc 05 dan turunan kebutuhan admin di Doc 06 — kategori fungsi, bukan salinan tampilan, teks, maupun aset.

Pass kedua (2026-11-16) bersifat **struktural dan tanpa login**, memakai REA (`rea-agents` 6.9.0) mode pasif lewat CDP: yang diambil hanya nama rute, kode status, nama file chunk, dan header HTTP — lihat Doc 08. Kami tidak enumerasi sub-rute admin dan tidak mencoba masuk ke permukaan apa pun selain halaman publik. `robots.txt` situs sumber memasang `Content-Signal: ai-train=no, use=reference` dan memblokir crawler AI secara eksplisit; penghormatan terhadap sinyal itu adalah bagian dari metode, dan fakta tersebut sekaligus bahan bicara dengan klien Anda.

## Asumsi yang belum dikonfirmasi (perlu jawaban)

Ukuran katalog & trafik tahun pertama · gateway mana yang punya akun (Midtrans/Xendit/Tripay) · kurir & kontrak tarif · provider WA (butuh approval template) · guest checkout ya/tidak · kebijakan deposit hangus + T&C (legal milik klien) · bahasa kedua · target tanggal launch · siapa penanggung jawab konten/foto produk.
Lihat daftar keputusan bisnis B-PO-1…B-PO-14 di `02` §2.2 dan `02` §7.

## Batas pekerjaan (penting, dibaca sebelum mulai)

Yang kita bangun adalah **platform baru dengan kategori fitur yang setara**. Yang tidak kita lakukan dan tidak bisa dibantu:
- menyalin desain/CSS/kode, teks marketing, foto produk, ilustrasi, ikon, logo, atau trade name situs mana pun;
- masuk memakai kredensial yang bukan milik Anda, mengambil data customer lain, atau mencoba naik hak akses ke panel admin sistem pihak lain. Peninjauan area login **akun customer milik sendiri** dengan izin pemiliknya diperbolehkan dan sudah dilakukan secara hanya-baca (§ Metode observasi);
- menyimpan data pribadi customer pihak ketiga sebagai "bahan referensi" — yang kami catat hanyalah kategori fitur dan jenis field.

Konsekuensi praktis: frontend memakai sistem tema/token orisinal, seeder demo berisi produk fiktif buatan sendiri, dan seluruh foto/deskripsi/kebijakan/legal disediakan klien. Kredensial apa pun yang tidak perlu jangan dibagikan di chat; kalau nanti butuh akses akun milik klien sendiri (gateway/kurir/panel), pakai environment variable + akun khusus, bukan kredensial pribadi.

## Langkah berikutnya

Dokumen fondasi selesai (01–10 + ADR-001/002). Langkah berikutnya, berurutan:

1. **Repo diaktifkan** — `git init` + `.gitignore` (tanpa kredensial, tanpa aset pihak ketiga). Ini prasyarat cicil-subagent: tiap task = satu commit.
2. **Spec Sprint 0** ditulis di `docs/specs/sprint-0-fondasi.md` (maks 2 halaman, sesuai Doc 03 §0), disetujui, lalu dipecah jadi plan implementasi dan task.
3. **Eksekusi Sprint 0** dengan `superpowers:subagent-driven-development`: satu subagent baru per task, dua review (kepatuhan spec → mutu kode) sebelum task dianggap selesai. Task dipilih agar tidak bertabrakan — pemecahannya kepemilikan modul (Doc 10 §5.1) dan satu PR satu modul.
4. **Jalur klien berjalan paralel sejak hari 1** (Doc 03 §9 + task 0.9): gateway mana yang punya akun, kurir, B-PO-1…14, aset brand, penanggung jawab konten. Item ini **blocker Sprint 2**, bukan Sprint 0.
