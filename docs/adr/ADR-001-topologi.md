# ADR-001 — Topologi: Modular Monolith dengan Jalur Ekstraksi Microservice

Tanggal: 2026-11-16 · Status: **Accepted** (dikonfirmasi pemilik proyek 2026-11-17 bersama pilihan jalur ±18 minggu, **yang sekarang berstatus komitmen awal pra-Blueprint** — lihat catatan Jadwal di bawah) · Konteks lengkap: `docs/01-architecture.md`

**Jadwal (amandemen 2026-11-17, ADR-003 K-5):** angka ±18 minggu yang disebut di ADR ini adalah **komitmen awal pra-Blueprint** dan sudah **diamandemen** — cakupan Blueprint penuh menuntut ±22–26 minggu, dan 18 minggu hanya bertahan pada opsi J-b/J-c (Doc 11 §5). Jadwal final adalah **keputusan klien yang masih terbuka → Q-1 (Doc 11 §6)**. Yang tidak berubah: keputusan topologi di ADR ini.

## Status

Kita memilih Opsi A: satu aplikasi Laravel modular dengan worker queue dan scheduler sebagai proses/container terpisah, plus transactional outbox sejak hari pertama. Opsi B (microservice-lite 4 service) menjadi roadmap dengan pemicu terukur; Opsi C (8–10 service + service mesh + K8s) ditolak.

## Konteks

- Tim 1–2 developer, target MVP ±14 minggu (7 sprint) dengan empat fitur khas yang semuanya diminta masuk MVP: RTS, PO dengan deposit/pelunasan, Request Order privat, tracking + notifikasi. *(Catatan kemudian: angka ini disusun sebelum temuan Doc 05/06/07; jadwal revisi ±18 minggu ada di Doc 03 §2 dan **sudah diamandemen lagi** menjadi ±22–26 minggu untuk cakupan Blueprint penuh (catatan Jadwal di atas, Doc 11 §5). Keputusan topologi di ADR ini tidak berubah — modular monolith justru menahan biaya tambahan itu tetap di satu pipeline.)*
- Model uangnya bernuansa stateful: deposit → pengingat → pelunasan → barang tiba → kirim. Alur ini menuntut konsistensi data dan rekonsiliasi yang keras.
- Target hosting VPS tunggal dengan Docker Compose; trafik awal diasumsikan <50 order/jam.
- Pemilik proyek meminta arsitektur microservice; permintaan itu valid sebagai tujuan jangka panjang, tapi harus diuji terhadap kapasitas tim dan risiko konsistensi.

## Alternatif yang dipertimbangkan

| | Manfaat | Biaya | Verdict |
|---|---|---|---|
| **A. Modular monolith + workers** | 1 pipeline, transaksi lokal (checkout+PO+voucher dalam satu DB transaction), auth session simpel, velocity tim kecil, mudah debug | scaling per proses saja (app vs worker), bukan per domain; godaan "semua di satu app" kalau boundary longgar | **Dipilih** — boundary + outbox membuat langkah ke B tetap mekanis |
| **B. Microservice-lite (4 service)** | scaling & deploy isolasi per domain, siap multi-brand/multi-vendor, failure domain kecil | saga + compensating refund untuk deposit/pelunasan, auth propagation, 4 CI/CD, duplikasi DTO, tracing, estimasi 40–60 % kapasitas tim habis di infrastruktur | Ditunda ke roadmap, trigger di `01` §9 |
| **C. Full microservice + K8s/mesh** | cocok untuk tim besar & trafik tinggi | biaya & kompleksitas jauh di atas kebutuhan; beban belajar; on-call untuk 2 orang | Ditolak |

## Keputusan

1. Bangun sebagai modular monolith dengan modul mengikuti Doc 01 §3.3 dan aturan boundary B1–B6.
2. `outbox` dan dispatcher event wajib ada di Sprint 0, bukan ditambahkan nanti.
3. Semua mutasi lewat Command, semua baca lewat Query/DTO → memungkinkan HTTP API headless tanpa mengubah domain.
4. Ekstraksi ke B hanya kalau trigger terjadi, dan diurutkan dari `Search` → `Notification` → `Payments` → `Fulfillment` → `Sourcing` → `Orders`.
5. Setiap keputusan boundary yang diambil di luar aturan B1–B6 harus ditulis sebagai ADR baru (contoh: relasi lintas modul untuk laporan boleh lewat read model materialized view, tidak lewat relasi Eloquent).

## Konsekuensi

**Positif:** jadwal MVP realistis; checkout konsisten tanpa saga; satu orang bisa memegang seluruh sistem; biaya infra rendah; jalur upgrade tetap terbuka.

**Negatif / yang harus diterima:**
- Isolasi kegagalan lemah — satu bug memory bisa mengganggu seluruh app. Mitigasi: resource limit, Horizon terpisah, healthcheck, queue panjang dipantau.
- Deploy bersama berarti rilis modul kecil berisiko ikut modul besar. Mitigasi: feature flag per modul + migration expand-migrate-contract.
- Disiplin boundary adalah utang yang terus ditagih: kalau ada relasi "sedikit" lintas modul, ekstraksi nanti jauh lebih mahal. Mitigasi: cek arsitektur (Deptrac/architecture test) di CI yang menolak import antar modul yang tidak lewat `Contracts/`.
- Jika klien memaksa microservice penuh di awal, konsekuensinya jadwal MVP bergeser +2–3 sprint dan alur deposit/pelunasan wajib didesain ulang sebagai saga; itu pilihan yang sah tapi harus disetujui sadar.

## Pertanyaan yang masih terbuka

Berapa target order/jam sesungguhnya (kalau jauh di atas asumsi, trigger B lebih cepat)? Apakah klien punya rencana brand kedua dalam 12 bulan (multi-tenant mendorong ekstraksi `Identity`/`Catalog` lebih dulu)?
