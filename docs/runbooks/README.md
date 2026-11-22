# Runbook insiden — Platform Elzara

**Rujukan induk:** SNOWEGO_MICROSERVICES_BLUEPRINT.md v1.0 §15 (daftar runbook wajib) dan §16 (test penerimaan).
Kumpulan ini menjawab kebutuhan **B-12** (Doc 11) — "runbook 11 skenario jadi deliverable Sprint 7+ dan daftar `docs/runbooks/`" — dan dipakai oleh **B-14** (Doc 11) / `docs/threat-model.md` §5.
Tanggal: 2026-11-17 · Status: **sebelas skenario Blueprint §15 sudah punya berkas sendiri** (lihat §1); isi dapat dijalankan setelah Sprint 0, dan sebagian besar langkah masih bergantung pada tool/keputusan yang belum ada (lihat §5).

---

## 1. Peta Blueprint §15 → berkas

Blueprint §15 mendaftar 11 "Runbooks wajib". **Pemilik pemetaan resmi adalah Doc 11 §8.2** (skenario ↔ nama berkas yang benar-benar ada); tabel di bawah mengikutinya dan tidak menciptakan daftar tandingan. Sejak sapuan ini, **sebelas skenario punya berkasnya masing-masing** — tidak ada lagi skenario yang "sebagian ditutup" oleh berkas lain.

| # | Skenario Blueprint §15 | Berkas | Catatan pemetaan |
|---|---|---|---|
| 1 | payment captured but order unpaid | `payment-callback-late.md` | callback lambat/hilang + status uang tak sinkron |
| 2 | duplicate webhook | `webhook-replay.md` | termasuk **webhook palsu** (forgery), bukan hanya duplikat |
| 3 | expired reservation but late payment | `reservation-expiry-race.md` | jalur `paid_stock_exception` (Blueprint §8.4) + pemilik urutan lock total (§3.4) |
| 4 | batch over-quota | `batch-over-quota.md` | penjaga kuota atomik (Blueprint §8.5), TOCTOU tutup batch vs enrollment, pembeda bug-vs-bot |
| 5 | stuck job / DLQ | `queue-stuck.md` | tujuh pool sesuai **Doc 11 §8.1** + pengeringan `outbox_events` |
| 6 | supplier failure | `supplier-failure.md` | `procurement_status = failed`, ETA, refund karena gagal sourcing (Blueprint §5.2 butir 8) |
| 7 | database failover | `database-failover.md` | **bukan failover** — satu node (Doc 11 **B-15**; Blueprint §14.1): pemulihan proses/restore + gelombang expirasi + rekonsiliasi wajib |
| 8 | MinIO unavailable | `object-storage-unreachable.md` | enam bucket **Doc 01 §9.2**; endpoint yang dijangkau browser vs hostname Docker |
| 9 | Cloudflare waiting room misconfiguration | `cloudflare-waiting-room.md` | + uji bypass (Blueprint §16); webhook **tidak boleh** terantri (Blueprint §10.2 butir 7) |
| 10 | refund reconciliation | `refund-reconciliation.md` | kelas E1–E5, `paid_stock_exception`, U5 tanpa auto-adjust |
| 11 | emergency disable checkout | `emergency-disable-checkout.md` | kill switch bertingkat L0–L3; pasangan proseduralnya `cloudflare-waiting-room.md` §3.6 |

**Lima berkas tambahan milik kami — penambahan, bukan pengganti angka 1–11** (kalimat penutup Doc 11 §8.2): `oversell.md`, `stock-discrepancy.md`, `backup-restore-drill.md`, `data-breach.md`, `rollback-release.md`. Fungsinya: `oversell.md` dan `stock-discrepancy.md` adalah tempat resolusi untuk skenario 3–4 dan anomali stok (T-31, T-14); `backup-restore-drill.md` adalah pasangan mekanis skenario 7 (bukti RTO/RPO + prosedur restore bertimed); `data-breach.md` menutup T-19/T-24/T-26/T-28/T-32; `rollback-release.md` menutup T-27/T-31 di jalur rilis.

Hubungan antar-berkas yang harus dibaca bersama: `database-failover.md` §3.3 ↔ `emergency-disable-checkout.md` §3.4 (webhook tidak boleh ikut mati), `batch-over-quota.md` §3.6 ↔ `refund-reconciliation.md` §3.4E4 ↔ `oversell.md` §3.4 (tiga keluaran `paid_stock_exception` yang sama, satu pemilik prosedur), `supplier-failure.md` §3.3 ↔ `refund-reconciliation.md` (refund massal hasil keputusan klien).

## 2. Konvensi bentuk (semua berkas)

Setiap runbook memakai lima bagian dengan judul yang sama:

1. **Deteksi** — metrik/alert/dash apa yang memberi tahu, plus ambang.
2. **Dampak** — apa yang rusak dan seberapa.
3. **Langkah tanggap** — bernomor, tiap perintah diikuti `[ verifikasi ]`.
4. **Eskalasi** — ke siapa, kapan.
5. **Setelah insiden** — apa yang dicatat, ADR/tiket yang dibuka.

### 2.1 Status verifikasi (wajib dibaca)

> Repo ini **belum berisi kode** (ADR-003 §Konsekuensi: "Kode yang sudah dibuat: nol"), dan target infra masih rencana (Doc 01 §14). Karena itu **setiap** blok perintah di dokumen ini diberi tanda `# belum diverifikasi di lingkungan ini`. Perlakukan sebagai kerangka kerja yang harus dicoba di staging dulu — bukan fakta bahwa perintah itu ada atau berjalan.

Aturan penulisan perintah:

- Prefiks baku untuk API: `docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan …` (susunan service dari Doc 01 §11.1; path API `apps/api` menurut Doc 11 §7). **Catatan:** dua berkas compose itu **belum ada di repo** (direktori `docker/` tidak ditemukan saat sapuan) — prefiks di bawah adalah bentuk yang dituju, bukan perintah yang sudah bisa dijalankan. Bila targetnya worker: ganti `app` → `worker`.
- Postgres/Redis/Elasticsearch/MinIO **tidak mempublikasikan port ke host** di produksi; akses lewat `exec` container atau SSH tunnel (Doc 01 §11.1). Perintah `psql`/`redis-cli`/`mc` di bawah memakai bentuk `exec` karena itu satu-satunya jalur yang terdokumentasi.
- Placeholder ditulis `<...>`. **Jangan pernah** mengisi dokumen ini dengan kredensial, nomor akun, token, atau data customer asli (Doc 00 §Batas pekerjaan; threat-model T-26).
- Nama tabel/role/queue diambil dari **Doc 11 §4** (daftar rename resmi) dan **B-10**, bukan dari draft Doc 01/00 yang sedang ditulis ulang paralel. Kolom spesifik yang belum dipastikan ditulis apa adanya + ditandai `# kolom perlu dikonfirmasi ke Doc 09 hasil rewrite`.

### 2.2 Larangan keras yang berlaku di semua runbook

| Larangan | Sumber | Ganti dengan |
|---|---|---|
| `UPDATE`/`DELETE` manual pada nilai uang atau status | B5 + M-7 (Doc 11 §2: tidak diubah Blueprint) | Command/aksi admin ber-audit; ledger entri baru |
| Mengubah baris tabel append-only (`payment_ledger`, `order_events`, `stock_movements`, `audit_logs`, `tracking_events`, `outbox_events`, `shipment_stage_events`, `review_moderation`) | S-6 + Doc 10 §5.6 | baris koreksi baru dengan `reverses_id` |
| Menghapus/mengedit migrasi yang sudah merge | M-4 | migrasi koreksi baru |
| Menyembunyikan masalah oversell/duplikat dengan menghapus jejak | Blueprint §8.4 butir 4; Doc 10 §5.6 | status exception + antrean rekonsiliasi |
| Menghapus kredensial yang bocor **saja** tanpa rotasi di provider | threat-model T-26, T-19 | rotasi/cabut di sisi provider, lalu ganti env (lihat `data-breach.md`) |
| Mengklaim HA / failover otomatis | Doc 11 B-15 | pemulihan manual + restore teruji |

### 2.3 Severity (dipakai di semua berkas)

| Level | Definisi contoh | Gerbang |
|---|---|---|
| **SEV-1** | kebenaran uang/stok salah, data customer tersebar, webhook palsu diproses, checkout mati | bangunkan penanggung jawab rilis segera; status page; decision log |
| **SEV-2** | satu lane melambat, antrean menumpuk, upload media gagal, callback tertunda di bawah ambang | tanggap jam kerja; catatan insiden |
| **SEV-3** | anomali tunggal yang tertangkap invariant/alert tanpa dampak pelanggan | triase terjadwal |

### 2.4 Ambang metrik bersifat **sementara**

Semua angka ambang di berkas-berkas ini ditandai **(sementara)** karena kapasitas teruji belum ada — Doc 11 §6 **Q-4** (butuh k6 di staging) dan Blueprint §1 ("Tentukan RPS/kapasitas melalui load test, bukan asumsi") dan §10.2 butir 1. Jangan mengganti angka ini tanpa hasil tes.

Metrik yang dirujuk berasal dari Blueprint §15 dan dashboard minimum Doc 01 §12: RPS, p50/p95/p99, 4xx/5xx, DB lock waits & deadlock, connection pool saturation, Redis memory, **queue lag**, **payment webhook delay**, **oversell anomalies**, **stock reservation expirations**, **MinIO error rate**, **waiting-room admissions & waiting time**, order stuck > 24 h di `paid`, intent expired vs captured, laju gagal bayar per provider.

## 3. Siapakah "siapa" di bagian Eskalasi

Struktur peran = **10 role RBAC** hasil B-10 (Blueprint §4): `superadmin`, `catalog-manager`, `sales-ops`, `preorder-ops`, `sourcing-ops`, `warehouse-staff`, `procurement-staff`, `finance`, `customer-support`, `analyst` (read-only). Runbook menyebut **peran**, bukan nama orang; rota on-call adalah tugas Sprint 7 ("runbook insiden + on-call sederhana", Doc 03 §8) dan sampai saat itu belum ada daftarnya.

Pemilik proyek (klien) memegang: akun gateway (Q-7), akun Cloudflare & plan (Q-6), kontrak kurir, template WhatsApp, kebijakan legal/privasi (Doc 00 §Asumsi).

## 4. Artefak yang harus ada supaya runbook ini bisa dijalankan

Dari Blueprint §19 (Definition of Done) dan Doc 03 §8 — semuanya masih terbuka:

- [ ] staging environment nyata dengan data uji orisinal (Blueprint §14.3)
- [ ] metrik + alert yang disebut §2.4 benar-benar dikirim (Blueprint §15)
- [ ] Horizon DLQ + replay teruji (Blueprint §19)
- [ ] backup terjadwal + **drill restore** (Doc 10 §9, Doc 03 §8)
- [ ] bypass test Cloudflare & Waiting Room under load (Blueprint §16; B-8)
- [ ] OWASP ZAP baseline (lihat `docs/threat-model.md` §6)

## 5. Butir terbuka (tidak bisa ditutup tim dev)

| # | Yang belum ada | Berkas yang terganggu | Rujukan |
|---|---|---|---|
| 1 | Provider pembayaran mana pun (nama, bentuk signature, endpoint resend, semantik settlement/refund) | `payment-callback-late.md`, `webhook-replay.md`, `oversell.md`, `reservation-expiry-race.md` | Q-7 → **ADR-006** (Doc 11 §3, §6) |
| 2 | Angka kapasitas teruji → ambang Waiting Room, rate limit, severity | `cloudflare-waiting-room.md`, `queue-stuck.md` | Q-4 → **ADR-009** |
| 3 | Ketersediaan fitur Waiting Room pada plan klien (fitur berbayar) | `cloudflare-waiting-room.md` | Q-6; Blueprint §10.2 butir 6 |
| 4 | Prosedur pencabutan sesi & revoke-all tetap perlu diverifikasi; mekanisme sesi Sanctum dua guard sudah diputuskan | `data-breach.md`, seluruh yang menyentuh sesi | **ADR-007**; Q-2 dijawab pemilik proyek 2026-11-22: klien tidak punya IdP/SSO sendiri, tidak ada provider OIDC. Artefak sign-off pemilik proyek dan klien masih ditunggu |
| 5 | Kebijakan bucket privat/publik + TTL presigned + jalur CDN | `object-storage-unreachable.md`, `data-breach.md` | **ADR-008** (Blueprint §18 butir 7) |
| 6 | Variabel env khusus *endpoint yang dijangkau browser* untuk presigned URL | `object-storage-unreachable.md` | Blueprint §11 (persyaratannya ada; **namanya tidak terdaftar di mana pun** — Doc 01 §11.3 hanya memisahkan environment, tidak mendaftar variabel env) |
| 7 | Berkas khusus "supplier failure" | ~~`oversell.md` §3.5 + `stock-discrepancy.md` §3.4~~ → sekarang `supplier-failure.md` | **ditutup oleh sapuan ini** (Doc 11 §8.2 baris 6); sisanya tetap terbuka sebagai keputusan cakupan klien |
| 8 | Entropi `claim_codes` + rate limit endpoint pelaporan kode | `data-breach.md` §3.6 | threat-model T-29 |
| 9 | RPO/RTO yang **diukur** (bukan diasumsikan) | `backup-restore-drill.md`, `database-failover.md`, `rollback-release.md` | Blueprint §13, §1; Doc 03 §8 |
| 10 | Berkas `docker/compose.yaml` + `docker/compose.prod.yaml` yang dirujuk semua prefiks perintah | **semua berkas** (prefiks §2.1) | direktori `docker/` belum ada di repo; dibuat di Sprint 0 (Doc 03) |
| 11 | ~~bucket `backups` tidak ada di enam bucket kanonik~~ → **dijawab**: dump backup WAL/volume disimpan **off-node**, bukan sebagai bucket aplikasi (Doc 11 §8.7); Doc 01 §11 dan `backup-restore-drill.md` sudah mengikuti | `backup-restore-drill.md`, `database-failover.md`, `object-storage-unreachable.md` | Doc 11 §8.7 + Blueprint §11/§13 |
| 12 | ~~flag kill switch belum terdaftar~~ → **dijawab**: namanya `checkout.enabled` (konvensi `.enabled`, Doc 11 §8.7), terdaftar di katalog flag Doc 09 §15; `503 CHECKOUT_DISABLED` hanyalah nama respons di Doc 12 §4.3 | `emergency-disable-checkout.md`, `cloudflare-waiting-room.md` §3.6 | Doc 09 §15 + Doc 12 §4.3 |
| 13 | Pool Horizon yang mengeksekusi payout refund & jendela retry provider | `refund-reconciliation.md`, `payment-callback-late.md`, `database-failover.md` | Doc 01 §10 (daftar pool) + **ADR-006** (Q-7) |

## 6. Indeks berkas

| Berkas | Satu baris |
|---|---|
| [`oversell.md`](oversell.md) | Anomali oversell/over-quota terdeteksi; resolusi `paid_stock_exception` (refund / realokasi / manual) |
| [`payment-callback-late.md`](payment-callback-late.md) | Webhook lambat/hilang; order sudah kedaluwarsa atau belum; rekonsiliasi & re-drive aman |
| [`queue-stuck.md`](queue-stuck.md) | Backlog Horizon + DLQ; prioritas `payments`/`inventory`, pause `reports`/`imports`; kuras outbox |
| [`webhook-replay.md`](webhook-replay.md) | Event duplikat atau palsu; cek signature, cari `webhook_receipts`, suppress vs accept |
| [`reservation-expiry-race.md`](reservation-expiry-race.md) | Bayar tiba saat/dekat TTL; kesepakatan urutan lock; resolusi manual |
| [`stock-discrepancy.md`](stock-discrepancy.md) | Stok fisik ≠ catatan; stocktake; adjustment selalu tercatat |
| [`object-storage-unreachable.md`](object-storage-unreachable.md) | MinIO gagal; endpoint browser vs hostname Docker; cek kebijakan bucket |
| [`cloudflare-waiting-room.md`](cloudflare-waiting-room.md) | Admission salah set / antrean panjang / dilewati; ubah threshold; buktikan bypass |
| [`backup-restore-drill.md`](backup-restore-drill.md) | Verifikasi backup terjadwal + drill restore triwulanan bertimed |
| [`data-breach.md`](data-breach.md) | Dugaan kompromi; containment, rotasi kredensial, invalidasi sesi, pembekuan akun staff, hukum, bukti |
| [`rollback-release.md`](rollback-release.md) | Deploy buruk; redeploy image sebelumnya; skema **tidak** ikut mundur |
| [`batch-over-quota.md`](batch-over-quota.md) | Kuota batch habis/terlewat; TOCTOU tutup batch vs enrollment; pembeda bug-vs-bot; larangan tambah kuota diam-diam |
| [`supplier-failure.md`](supplier-failure.md) | Supplier gagal/EOS/telat; `procurement_status = failed`; ETA; refund massal satu aksi; realokasi; jalur komunikasi CS |
| [`database-failover.md`](database-failover.md) | Primer Postgres mati di satu node: klasifikasi A/B/C, keputusan terima-kehilangan vs tunggu, gelombang expirasi, rekonsiliasi wajib |
| [`refund-reconciliation.md`](refund-reconciliation.md) | Antrean eksepsi uang E1–E5; refund ganda/salah nominal; U5 tanpa auto-adjust; mapping `refunds.status` ↔ sumbu uang |
| [`emergency-disable-checkout.md`](emergency-disable-checkout.md) | Kill switch bertingkat L0–L3; apa yang boleh dan tidak boleh ikut mati; webhook tetap hidup; urutan buka kembali |
| [`rbac-access-changes.md`](rbac-access-changes.md) | Pencabutan izin admin dengan invalidasi cache dan prosedur bypass manual berjejak. Status runtime tercantum di halaman. |
