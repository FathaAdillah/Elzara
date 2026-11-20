# Runbook — "Failover" database pada topologi satu node VPS (deteksi → PITR/WAL → re-point → rekonsiliasi)

**Severity awal:** SEV-1 (DB adalah sumber kebenaran uang & stok). Tidak ada jalur SEV-2: pada satu node, DB tumbang = platform tumbang.
**Rujukan:** Blueprint §15 "database failover", §14.1 (peringatan: **Docker Compose single-node = SPOF**; "jangan klaim HA tanpa multi-node, replikasi, failover, recovery teruji") · Doc 11 **B-15** (VPS tunggal + Compose; **tidak ada klaim HA**; target = satu node dengan backup teruji + restore drill + pemulihan manual, bukan 99.9% otomatis), **B-7** (outbox/inbox/webhook_receipts), §8.3 · Doc 01 §12 (RPO/RTO **sementara**, ambang **Q-4**), §11 (observability) · Blueprint §9 (no side effects before DB commit) · `docs/threat-model.md` (T-20 kehabisan sumber daya satu node) · runbook `webhook-replay.md`, `backup-restore-drill.md`, `queue-stuck.md`, `payment-callback-late.md`

> **Jujur soal kata "failover".** Kami **tidak punya** replika/secondary (B-15). Yang dijalankan di sini adalah **pemulihan (recovery) PITR di node tunggal**, bukan failover otomatis ke node sehat. Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`**. Nama tool backup/WAL (pgBackRest / WAL-G / `pg_basebackup`) **belum diputus di repo** — bila perintah restore di bawah tidak cocok dengan toolchain produksi, **tanyakan ke operator/pemilik infra**, jangan dipaksakan.

---

## 1. Deteksi

| Sinyal | Sumber | Ambang **(sementara — Q-4)** |
|---|---|---|
| Postgres menolak koneksi / crash-loop | `pg_isready`, `docker compose ps db`, log `db` | gagal > 1 menit ⇒ SEV-1 |
| Healthcheck app gagal karena DB | health endpoint, error rate 5xx | 5xxcheckout/API naik mendadak ⇒ SEV-1 |
| WAL archive error | log tool WAL/backup | archive gagal > **(Q-4)** menit ⇒ RPO memburuk, catat |
| Disk penuh (WAL/data) | metrik node | mendekati limit ⇒ DB berhenti menulis; **ini** penyebab paling umum di satu node |
| worker/app ikut mati | `docker compose ps` | DB mati menyeret semua container satu node (B-15) |

## 2. Dampak

Satu node = satu titik gagal (B-15 / Blueprint §14.1). Ketika Postgres tumbang:

- Semua jalur mutasi berhenti. Tidak ada checkout, tidak ada capture tercatat, tidak ada dispatch outbox.
- **Yang sedang mengudara ikut beku** dan wajib dihitung saat pulih (§3.2): baris `outbox_events` dengan `dispatched_at IS NULL`, `payment_intents`/webhook yang tetap **masuk** dari provider selama DB tumbang, dan `stock_reservations` yang `expires_at`-nya lewat karena worker `inventory` mati.
- **Tidak ada pemulihan otomatis.** Dev tidak "menukar node". Ini keputusan operasional + infra (lihat §4).

## 3. Langkah tanggap

### 3.1 Keputusan pertama: tunggu vs pulih (terima kehilangan data)

Ini keputusan **RPO/RTO**, bukan teknis belaka. Ambang **sementara — Q-4** (Doc 01 §12).

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec db pg_isready            # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml ps                            # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --tail=300 db             # belum diverifikasi di lingkungan ini
```

| Situasi | Pilihan | Konsekuensi |
|---|---|---|
| DB masih hidup, hanya lambat/kehabisan RAM | **tunggu + kurangi beban** (pause `reports`/`imports` — lihat `queue-stuck.md` §3.3); jangan restore | tidak ada kehilangan data |
| WAL terakhir beberapa **detik** lalu | **pulih ke titik itu** — data loss kecil | RPO ~detik **(Q-4)** |
| WAL terakhir **menit/jam** lalu, atau korup | **putuskan bersama klien**: restore PITR = terima kehilangan transaksi sejak WAL terakhir | RPO membesar — **harus disetujui, bukan diputus dev** |

`[ verifikasi ]` waktu WAL/archive **terakhir** dicatat, dan keputusan "tunggu vs pulih + ambang data loss" ditulis di log insiden dengan nama penyetuju (klien untuk RPO > 0).

### 3.2 Sebelum menyentuh restore: bekukan "yang sedang mengudara"

```sql
-- 1) outbox yang belum keluar (append-only — JANGAN dihapus/diedit)
SELECT COUNT(*) AS belum_dispatch, MIN(occurred_at) AS tertua
FROM outbox_events WHERE dispatched_at IS NULL;

-- 2) webhook yang tetap diterima provider selama DB tumbang (dedup (provider, provider_event_id))
SELECT provider, provider_event_id, received_at
FROM webhook_receipts
WHERE received_at > NOW() - INTERVAL '1 hour'
ORDER BY received_at DESC;

-- 3) reservasi yang lewat masa berlaku SAAT worker inventory mati
SELECT COUNT(*) AS kedaluwarsa_selama_mati, MIN(expires_at) AS paling_lama
FROM stock_reservations
WHERE expires_at < NOW() AND expires_at > NOW() - INTERVAL '6 hours';   -- [ verifikasi ] kolom penanda "released/diselesaikan" → konfirmasi Doc 09
```

`[ verifikasi ]` ketiga angka tersimpan. Ini **bukan** untuk diubah sekarang, tapi daftar objek yang wajib direkonsiliasi **setelah** DB kembali (§3.5). Webhook selama mati **tetap diterima & disimpan** (payload mentah di `webhook_receipts`), diproses ulang lewat `webhook-replay.md` — jangan dijawab "gagal" ke provider.

### 3.3 PITR / restore WAL ke titik teraman

> Alat restore bergantung toolchain backup produksi (belum diputus). **Bila operator belum menetapkan pgBackRest / WAL-G / basebackup+archive, STOP dan tanyakan** — langkah ini tidak boleh dikarang.

```bash
# bentuk umum — GANTI <tool> sesuai toolchain yang benar-benar dipakai (belum diverifikasi di lingkungan ini)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml stop app worker scheduler        # belum diverifikasi di lingkungan ini — hentikan penulis sebelum restore
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec db <tool> recover --to-target <timestamp>   # belum diverifikasi — <tool>/flag dari operator
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml up -d db                          # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` DB menerima koneksi (`pg_isready` OK) dan titik PITR (`--to-target`) sesuai keputusan §3.1. **Jangan** menaiki app/worker sebelum DB sehat.

### 3.4 Re-point aplikasi, lalu nyalakan bertahap

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan migrate:status     # belum diverifikasi di lingkungan ini — hanya bila ada drift skema
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml up -d app                               # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml up -d worker                            # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` app hidup, konek DB benar. Urutan nyalakan: **DB → app → worker (pool `payments` & `inventory` dulu — Blueprint §9)** → scheduler. Webhook boleh mulai direplay dari `webhook_receipts` (`webhook-replay.md`).

### 3.5 Verifikasi pasca-pulih (invarian)

```sql
-- A. invarian stok: ledger vs agregat. Tidak boleh ada baris dengan selisih.
SELECT si.warehouse_id, si.sku_id, si.on_hand,
       COALESCE(SUM(sm.quantity_delta),0) AS ledger_sum
FROM stock_items si
LEFT JOIN stock_movements sm
       ON sm.warehouse_id = si.warehouse_id AND sm.sku_id = si.sku_id
GROUP BY si.warehouse_id, si.sku_id, si.on_hand
HAVING si.on_hand <> COALESCE(SUM(sm.quantity_delta),0);   -- [ verifikasi ] nama kolom delta → konfirmasi Doc 09

-- B. reservasi kedaluwarsa selama mati: pastikan expiry dijalankan (lepas reserved), bukan dilewati
SELECT COUNT(*) AS belum_dilepas
FROM stock_reservations
WHERE expires_at < NOW();                                   -- [ verifikasi ] filter "belum dilepas" → konfirmasi Doc 09

-- C. order awaiting_payment yang seharusnya sudah kedaluwarsa selama DB mati
SELECT COUNT(*) AS menunggu_pembersihan
FROM orders
WHERE order_status = 'awaiting_payment'
  AND created_at < NOW() - INTERVAL '30 minutes';           -- [ verifikasi ] TTL checkout → konfirmasi Doc 01/02 (sementara — Q-4)
```

```sql
-- D. payment_ledger vs provider (rekonsiliasi manual, TANPA auto-adjust — lihat refund-reconciliation.md §3.4)
-- bandingkan total payment_ledger per rentang outage dengan settlement provider; selisih → dashboard eksepsi, bukan UPDATE.
SELECT COUNT(*) AS tangguh_saat_outage
FROM payment_intents
WHERE status IN ('captured','succeeded')
  AND updated_at BETWEEN '<start_outage>' AND '<end_outage>';   -- [ verifikasi ] enum status + nama kolom → konfirmasi Doc 09 (payment_ledger/payment_intents)
```

`[ verifikasi ]` A kosong, B/C/D dihitung dan **direkonsiliasi** (bukan dipaksa cocok). Selisih apa pun → tiket + dashboard eksepsi.

### 3.6 Larangan keras (mutlak)

- **Jangan** menandai `outbox_events.dispatched_at` manual "biar bersih" — efek (resi, notifikasi, capture) hilang selamanya padahal tercatat terkirim (`queue-stuck.md` §3.5).
- **Jangan** mengedit `stock_movements` — ledger **append-only** (S-6); koreksi = baris pembalik baru, bukan UPDATE.
- **Jangan** `pg_resetwal` tanpa vendor/pemilik infra — berisiko merusak data secara permanen; di satu node tanpa replika ini bisa menghapus kemampuan pulih.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| RPO > 0 (terima kehilangan transaksi) | **klien** — persetujuan eksplisit | sebelum restore |
| Perubahan resource/infra (RAM, disk, CPU, tambah node/replika) | **klien / pemilik infra** — **bukan** keputusan dev | sebelum tindakan |
| Restore butuh toolchain WAL/backup yang tidak jelas | operator/pemilik infra | jangan jalankan sembarangan |
| Ditemukan `dispatched_at` diedit / ledger diubah | `superadmin` + `data-breach.md` | segera |
| Data loss material bagi buyer (uang/stok) | penanggung jawab rilis (SEV-1) + akunting klien | segera |

## 5. Setelah insiden

1. Catat **durasi outage** dan **delta** (transaksi yang hilang karena PITR, jumlah outbox tak terkirim, reservasi kedaluwarsa, order expired) — ini **bahan utama `backup-restore-drill.md`** (§5) untuk membuktikan RTO/RPO nyata, bukan klaim.
2. Perbarui `docs/threat-model.md` T-20 (kehabisan sumber daya satu node) bila penyebabnya resource.
3. Buka/tambah tiket ADR bila pemulihan menyentuh keputusan belum ada: toolchain backup/WAL (Sprint 0), angka RPO/RTO final (**Q-4**).
4. Rekonsiliasi uang selesai penuh lewat `refund-reconciliation.md`/`payment-callback-late.md`; webhook direplay (`webhook-replay.md`); stok selisih → `stock-discrepancy.md`.
5. Jangan menulis "sistem HA" di mana pun — B-15 melarang klaim HA tanpa multi-node/replikasi/failover/recovery teruji.
