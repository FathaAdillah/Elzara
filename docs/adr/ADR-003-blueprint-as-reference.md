# ADR-003 — Blueprint sebagai satu-satunya rujukan; topologi berubah ke API-first tiga aplikasi

Tanggal: 2026-11-17 · Status: **Accepted** (instruksi pemilik proyek 2026-11-17)
Rujukan baru: `C:\Users\Raze PC\Downloads\SNOWEGO_MICROSERVICES_BLUEPRINT.md` (v1.0, 17-11-2026) — disebut **Blueprint** di semua dokumen.
Menggantikan kesimpulan: **ADR-001 §Keputusan** (Inertia satu-aplikasi) dan **Doc 01 delta D8** sebagai *keputusan*; keduanya tetap berlaku sebagai *bukti*.

## Konteks

Semua dokumen 00–10, spec Sprint 0, dan plan implementasi ditulis sebelum Blueprint ada. Keputusan besarnya: Opsi A "modular monolith dengan satu aplikasi Inertia untuk storefront + admin" (ADR-001), dengan alasan yang sebagian bersandar pada hasil reverse engineering situs referensi (Doc 08 §3: shell `/admin`, `/dashboard/tracking`, `/dashboard/invoices` berbagi chunk `page-6490b052015716e7` yang sama → satu aplikasi Next.js; Doc 08 §4: satu endpoint klien, tidak ada API JSON publik).

Blueprint menetapkan arah yang berbeda dan lebih luas: `commerce-api` (Laravel) + `commerce-admin` (Next.js) + `commerce-web` (Next.js) di satu monorepo, API-first dengan OpenAPI 3.1 sebagai source of truth, empat sumbu status, model stok ledger dengan `on_hand/reserved/damaged/in_transit`, idempotency key di level API, landed cost & custom leg internasional sebagai entitas, Cloudflare Waiting Room sebagai admission control, dan enam bucket MinIO dengan presigned upload.

Instruksi pemilik proyek: Blueprint menjadi **single source of truth**; dokumen pengetahuan yang sudah ada diubah untuk merujuknya.

## Keputusan

**K-1.** Blueprint adalah rujukan induk. Dokumen 00–10 berubah status menjadi *turunan (derived)*: bila bertentangan dengan Blueprint, Blueprint yang benar, kecuali butir **K-4**.

**K-2.** ADR-001 tetap berdiri untuk bagian yang tidak dibatalkan Blueprint — yaitu **modular-first pada sisi domain**: Blueprint §2.1 sendiri menyebut satu Laravel API dengan satu database "masih modular monolith di sisi domain, bukan microservices", dan §2.2 merekomendasikan ekstraksi bertahap. Yang dibatalkan: **satu aplikasi UI**. Ganti dengan: satu API + dua aplikasi frontend terpisah yang di-deploy mandiri (Blueprint §3.1 layout monorepo).

**K-3.** Nomor ADR tidak boleh bertabrakan dengan daftar Blueprint §18. Aturan penamaan yang dipakai mulai hari ini: ADR kita bernomor terus (001, 002, 003, …) dan **Blueprint §18 butir 1–10 dipetakan** ke ADR kita di Doc 11 §4, bukan disalin ulang nomornya.

**K-4.** Batas "bukti vs keputusan". Blueprint §7 baris terakhir disclaimer-nya jujur: *"Struktur internal Snowe GO tidak diverifikasi."* Hasil RE kita (Doc 08) justru memverifikasi sebagian: situs sumber **tidak** berjalan pada topologi tiga aplikasi API-first yang digambar Blueprint §3. Konsekuensinya: Blueprint dipakai sebagai **resep**, bukan sebagai deskripsi realitas; dan klaim apa pun di dokumen yang berbunyi "situs sumber memakai arsitektur ini" adalah salah dan harus dihapus. Fakta Doc 08 tidak diubah — hanya kesimpulan keputusannya yang diganti.

**K-5.** Yang tidak ikut berubah, karena Blueprint tidak melarangnya dan kita sudah punya alasan tertulis: batas kekayaan intelektual (Doc 01 §13, Doc 00 §Batas), metode observasi hanya-baca (Doc 05/08), uang integer rupiah (Blueprint §6 mengizinkan "integer minor units"; alternatif `NUMERIC(20,4)` kita tolak supaya satu representasi — lihat Doc 11 §3.2), aturan migrasi M-1…M-13 (Blueprint §14.3 justru meminta expand/contract yang sama), dan keputusan opsi (a) ±18 minggu sebagai **komitmen awal** — dengan catatan jadwal harus dihitung ulang terhadap Blueprint §17 (Doc 11 §5, konsekuensi K-7).

**K-6.** Dokumen yang belum ada dan sekarang menjadi kewajiban karena Blueprint memakainya sebagai rujukan: kontrak API (OpenAPI 3.1), `threat-model.md` (Blueprint §3.1 menaruhnya di `docs/`), runbook 11 skenario (Blueprint §15), dan `ORDER_STATE_MACHINES.md` (Blueprint §5/§8.3 menyebut state machine sebagai penjaga transisi mundur). Blueprint §20 daftar "Next document candidates" identik dengan daftar ini — kita memenuhinya sebagai dokumen turunan, bukan menyalin Blueprint.

## Konsekuensi

- **Berubah berat:** Doc 01 (topologi, aplikasi, kontrak, status, keamanan), Doc 04 (Next.js dua aplikasi, bukan Inertia), Doc 09 (nama tabel + ~22 tabel baru), Doc 10 (jalur file `apps/commerce-api`, band modul baru), Doc 02 (empat sumbu status + idempotensi), Doc 03 (roadmap → Phase 0–4 Blueprint; jadwal naik).
- **Berubah ringan:** Doc 00 (indeks + status + rujukan), Doc 05/06/07/08 (tambah catatan "fakta ini, keputusan diambil dari Blueprint").
- **Kadaluarsa dan harus ditulis ulang:** `docs/specs/sprint-0-fondasi.md` (§4 deviasi lingkungan, task 0.1/0.5 berubah) dan `docs/superpowers/plans/2026-11-17-sprint-0-fondasi.md` — Task 3 (scaffold Inertia), Task 4 (loader modul di app tunggal), Task 13 (komponen untuk Inertia), Task 14 (CSP untuk app tunggal) tidak lagi benar untuk dua app Next + API. Plan lama disimpan sebagai artefak pra-Blueprint (di-tag `superseded-by-ADR-003` di baris statusnya), tidak dihapus, supaya jejak keputusan tetap terbaca.
- **Kode yang sudah dibuat:** nol. Repo baru berisi `docs/` + `.gitignore`/`.gitattributes`; belum ada scaffold, jadi perpindahan ini tidak memaksa migrasi kode — hanya dokumen.
- **K-7 (jadwal):** Blueprint §17 Phase 1 sendiri = 3–5 sprint untuk RTS MVP, sedangkan plan lama mengirim Sprint 1–3 untuk katalog+cart+checkout+ops. Dengan dua app frontend + kontrak API + load/concurrency test (Blueprint §16) yang sebelumnya tidak ada di cakupan, jalur kritis realistis menjadi **±22–26 minggu**, bukan 18. Ini bukan keputusan untuk diambil diam-diam: lihat Doc 11 §5 dan pertanyaannya untuk pemilik proyek.

## Alternatif yang ditolak

| | Ditolak | Alasan |
|---|---|---|
| A | Tetap Inertia satu-aplikasi, ambil hanya butir Blueprint yang "cocok" | itu bukan "single source of truth"; instruksinya eksplisit, dan memilih-milih rujukan menghasilkan dokumen yang saling menyangkal |
| B | Microservices penuh sejak hari pertama (10 service Blueprint §2.2) | Blueprint §2.1/§2.2 sendiri menolaknya untuk tim kecil; bertentangan dengan alasan ADR-001 yang masih berlaku |
| C | Rewrite semua dokumen dari nol mengikuti Blueprint, buang 00–10 | kita kehilangan bukti observasi (Doc 05/07/08), aturan S-1…S-10/M-1…M-13, dan jejak ADR yang justru dipakai untuk menegosiasikan jadwal |

## Status setelah perubahan

Dokumen turunan ditandai di baris keduanya dengan `Rujukan induk: Blueprint … (ADR-003)`. Matriks bentrokan dan resolusi per butir ada di **Doc 11 — Blueprint Reconciliation**; Doc 11 adalah satu-satunya tempat yang membicarakan perbedaan, supaya dokumen lain tidak penuh catatan sejarah.
