# Runbook — Bayar datang saat/dekat reservasi kedaluwarsa (race TTL)

**Severity awal:** SEV-1 (skenario Blueprint §15 "expired reservation but late payment"; aset A-3 + A-4; ancaman T-14, T-36)
**Rujukan:** Blueprint §8.4 butir 1–5 (kasus sulit: penyelarasan TTL, worker expiry, urutan lock bersama, `paid_stock_exception`, reconciliation job + dashboard exception queue), §8.1 (kondisi reservasi atomic + urutan lock SKU + retry deadlock), §8.2 (`SELECT ... FOR UPDATE` transaksi singkat; CAS/status guards; Redis lock **bukan** pengganti constraint), §8.5 (penutupan batch = lock state + tolak enrollment + snapshot, tanpa TOCTOU), §5.1 (state `expired`; "paid webhook arriving near expiry requires explicit reconciliation policy"), §16 (test "Payment webhook vs expiry job race") · Doc 11 **B-6** (`stock_reservations.expires_at`, `orders.paid_stock_exception`), **B-3** (empat sumbu status) · `docs/threat-model.md` T-14/T-36/T-11 · Blueprint §18 butir 4 → **ADR-005**

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1).

---

## 1. Deteksi

| Sinyal | Sumber | Ambang **(sementara — Q-4)** |
|---|---|---|
| `stock reservation expirations` (Blueprint §15) melonjak | metrik | > 3× baseline/jam |
| `orders.paid_stock_exception` bertambah (B-6) | query §3.1 | ≥ 1 ⇒ SEV-1 (selalu ada calon kerugian) || webhook `paid` datang dalam ±5 menit sebelum `stock_reservations.expires_at` | log/korelasi `webhook_receipts.received_at` vs `expires_at` | ≥ 1 ⇒ awasi, belum insiden |
| deadlock PostgreSQL + retry naik | metrik Blueprint §15; exception domain `InsufficientStock` / deadlock di log worker (Doc 01 §11) | ≥ 5/menit ⇒ **urutan lock dilanggar** — §3.4 |
| `payment_status = paid` tapi `stock_reservations.status = expired` | query §3.1 | ≥ 1 ⇒ insiden nyata, §3.3 |
| reservation `active` tanpa owner (cart mati / quote basi) | metrik "reservation tanpa owner" (Doc 01 §12) | menumpuk ⇒ worker expiry macet, lihat `queue-stuck.md` |
| order tertahan `paid` > 24 h | dashboard Doc 01 §11 | ≥ 1 ⇒ sudah melewati tahap "tunggu" |

## 2. Dampak

Uang masuk tapi stok/kuota sudah dilepas. Bila kita "menganggap stok tersedia" dan mengirim, kita **oversell** — dan karena stok yang dijanjikan belum pasti ada, kerusakannya bukan satu order tapi seluruh janji ke buyer lain (Doc 01 §12). Bila kita biarkan tanpa status, buyer melihat "sudah dibayar, tidak ada kabar", tim tidak punya antrean kerja, dan rekonsiliasi harian kehilangan satu jejak (U5). Untuk Pre-order, kuota batch yang dilepas juga berarti slot yang hilang untuk peserta antre.

## 3. Langkah tanggap

### 3.1 Kenali keadaannya (bukan menebak dari layar customer)

```sql
-- calon & korban: uang sudah capture, reservasi sudah mati
SELECT o.id, o.order_number, o.order_type,
       o.payment_status, o.fulfillment_status, o.procurement_status,
       o.paid_stock_exception,
       pi.expires_at          AS intent_expires_at,
       sr.id                  AS reservation_id,
       sr.status              AS reservation_status,
       sr.expires_at          AS reservation_expires_at,
       wr.received_at         AS webhook_received_at
FROM orders o
LEFT JOIN payment_intents pi ON pi.order_id = o.id        -- pengikat intent boleh via invoices (ADR-002); belum diverifikasi
LEFT JOIN stock_reservations sr ON sr.order_id = o.id
LEFT JOIN webhook_receipts wr ON wr.intent_id = pi.id
WHERE o.payment_status = 'paid'
  AND (sr.status = 'expired' OR sr.id IS NULL)
ORDER BY wr.received_at DESC NULLS LAST;
```

`[ verifikasi ]` tiap baris punya jawaban tiga hal: (a) apakah pembayaran **sah** sudah tervalidasi (signature + merchant + amount + currency + reference — `webhook-replay.md` §3.3), (b) apakah reservasi memang dilepas **sebelum** webhook tiba (`received_at > reservation_expires_at`), (c) apakah `paid_stock_exception` sudah terisi. Empat sumbu status (B-3) dibaca terpisah; jangan mencampur `payment_status` dengan `fulfillment_status`.

```sql
-- race yang sedang terjadi SEKARANG: webhook dalam jendela TTL
SELECT o.order_number, sr.expires_at, NOW() - sr.expires_at AS selisih
FROM orders o
JOIN stock_reservations sr ON sr.order_id = o.id
WHERE sr.status = 'active'
  AND sr.expires_at BETWEEN NOW() - INTERVAL '2 minutes' AND NOW() + INTERVAL '5 minutes';   # jendela ini sementara; ganti dari hasil load test + ADR-005
```

`[ verifikasi ]` tahu berapa banyak order di "zona bahaya" **sebelum** memutuskan apa pun. Jangan menambah zona bahaya baru: tahan pemenuhan untuk daftar ini (§3.2).

### 3.2 Bekukan pemenuhan untuk zona bahaya, bukan seluruh toko

Aturan yang mengikat (Blueprint §8.4 butir 4): **jangan menganggap stok tersedia**. Karena itu langkah pertama selalu menghentikan **pengiriman**, bukan menghentikan pembayaran.

```bash
# tahan jalur pick/pack untuk order terdampak (fitur flag / status tahan, bukan UPDATE status uang)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'terapkan tahan-pemenuhan per order melalui Command Orders';"   # belum diverifikasi — perintah nyata belum ada (belum ada kode)
```

`[ verifikasi ]` tidak ada paket baru dibuat untuk order di daftar §3.1, checkout publik tetap hidup, dan tidak ada satu pun `payment_status` yang berubah karena tangan manusia.

> Kalau volume zona bahaya besar (mis. ribuan reservasi expire bersamaan), kemungkinan besar **worker expiry** yang macet/terlambat, bukan pembeli yang aneh. Cek `queue-stuck.md` §3.3 (prioritaskan antrian `inventory`) dan matikan sumbernya sebelum memutuskan resolusi per order.

### 3.3 Coba reservasi ulang — dan terima kegagalan sebagai hasil resmi

```sql
-- ulangi primitives yang sama seperti di jalur normal: conditional UPDATE, bukan baca-lalu-tulis
UPDATE stock_items
SET reserved = reserved + :qty, updated_at = NOW()
WHERE warehouse_id = :warehouse_id
  AND sku_id = :sku_id
  AND on_hand - reserved - unavailable >= :qty
RETURNING id, reserved;      -- 0 baris = tidak tersedia. Titik. (Blueprint §8.1)  # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` hasil hanya dua: **berhasil** (maka order lanjut ke `paid → processing` normal, dan semua ini tercatat lewat Command/aggregate, B5) atau **gagal 0 baris** (maka **wajib** ditandai `paid_stock_exception`). Tidak ada hasil ketiga, dan tidak ada "coba nanti sore".

Untuk Pre-order/Group Order, yang di-reserve ulang adalah **kuota batch**, bukan stok fisik, dan hanya selama batch masih `open` (Blueprint §8.5):

```sql
UPDATE preorder_batch_items
SET reserved_qty = reserved_qty + :qty
WHERE id = :batch_item_id
  AND reserved_qty + :qty <= quota
  AND status = 'open'
  AND opens_at <= NOW()
  AND closes_at > NOW()
RETURNING id;                # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` batch yang sudah `closing`/`closed` **menolak** enrollment baru (Blueprint §5.2 butir 4 + §8.5): kalau kuota masih bisa bertambah setelah cutoff, itu bug TOCTOU — SEV-1, tulis isu.

### 3.4 Kesepakatan urutan lock (bagian yang mencegah deadlock sekaligus mencegah salah lepas)

Aturan ini dipakai **bersama** oleh webhook worker dan expiry worker — Blueprint §8.4 butir 3: "Webhook dan expiry worker memakai urutan lock yang sama dan CAS/status guards".

**Urutan total yang wajib (selalu, di semua jalur, tanpa pengecualian):**

1. `orders` (baris order yang sama) — `SELECT ... FOR UPDATE`, paling awal, karena order adalah agregat pemegang state;
2. `stock_reservations` milik order itu (status guard: hanya boleh berubah `active → committed|released|expired`);
3. `stock_items` per `(warehouse_id, sku_id)` **dalam urutan `sku_id` menaik** (untuk cart multi-SKU; Blueprint §8.1 — urutan konsisten untuk menekan deadlock);
4. `preorder_batch_items` (hanya jalur PO) lalu state `preorder_batches` bila operasi menyentuh tutup batch (Blueprint §8.5: quota row & batch state dalam protokol lock yang sama);
5. `invoices` / `payment_intents` yang ikut berubah;
6. tulis `order_events` + `stock_movements` + `payment_ledger` + `outbox_events` **dalam transaksi yang sama**, lalu COMMIT.

**Larangan yang membuat kesepakatan ini berarti:**

- **Jangan pernah** menahan lock melewati panggilan eksternal (API gateway/kurir/WA) — Blueprint §8.2 menuntut transaksi singkat; effect keluar proses dikirim lewat outbox **setelah** commit (B4).
- **Jangan** memakai Redis lock sebagai penjamin kebenaran. Ia hanya optimasi hot key/scheduler; lease bisa kedaluwarsa (Blueprint §8.2). Kebenaran dijamin conditional UPDATE + constraint + state guard.
- **Jangan** menulis CAS dengan membaca lalu menulis di dua transaksi (TOCTOU): `UPDATE ... WHERE status = 'active'` adalah CAS-nya.
- Retry deadlock: backoff pendek + jumlah percobaan berbatas **di level Command** (Doc 11 B-6), bukan `retry forever`.
- Bila ada operasi yang butuh urutan berbeda (mis. tool admin yang mengunci `stock_items` lebih dulu), **hentikan tool itu** dan buat ia mengikuti urutan di atas — bukan sebaliknya.

```bash
# bukti empiris urutan dilanggar: deadlock log per pasangan query
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --since=1h worker app | grep -iE "deadlock detected|deadlock_found|retry"   # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` tidak ada deadlock baru selama 15 menit setelah tindakan. Kalau masih ada, jangan tambah timeout — temukan jalur yang membalik urutan (kandidat utama: jalur admin manual, jalur refund, dan scheduler tutup batch).

```sql
-- siapa menahan apa sekarang (Postgres 16)
SELECT pid, state, wait_event_type, wait_event, left(query, 120) AS query
FROM pg_stat_activity
WHERE state <> 'idle' AND now() - xact_start > interval '5 seconds'
ORDER BY xact_start;           # belum diverifikasi di lingkungan ini
```

### 3.5 Resolusi `paid_stock_exception` (manual, terpilih, tercatat)

Ketiga opsi dan syaratnya ada di `oversell.md` §3.4 (A refund / B realokasi / C tunggu-manual). Yang spesifik untuk race ini:

- **Refund (A)** adalah jawaban default bila stok pengganti tidak pasti dalam jendela yang bisa dijanjikan. Eksekusi lewat `RefundCommand` (aggregate), dengan ledger `source = 'refund'`; **tidak ada auto-adjust** (U5) dan **provider-agnostic** sampai ADR-006 (README §5 butir 1).
- **Realokasi (B)** hanya sah kalau reservasi ulang di §3.3 berhasil pada SKU/varian/batch yang disetujui buyer. Perubahan nominal melewati ambang ⇒ wajib re-konsen buyer (RS-4, Doc 02).
- **Tunggu manual (C)** hanya kalau batch masih `purchasing`/`inbound` dan ETA baru bisa dijanjikan; order tetap di status exception sambil menunggu, jangan dipindah paksa ke `ready_to_ship` (gerbang RS-1, Doc 02).

```bash
# dashboard antrean pengecualian yang diminta Blueprint §8.4 butir 5
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'buka layar Dashboard > Alerts / Payments exception queue';"   # belum diverifikasi — layar ini deliverable Phase 2 (Blueprint §17)
```

`[ verifikasi ]` reconciliation job sudah jalan terhadap provider (Blueprint §8.4 butir 5) sehingga tidak ada order exception yang ternyata **tidak** benar-benar tertagih di sisi provider. Tidak satu pun baris di-`resolve` tanpa: aktor, alasan, opsi terpilih, dan jejak di `audit_logs` + ledger/`stock_movements`.

### 3.6 Lepas pembekuan

`[ verifikasi ]` sebelum melepas tahan-pemenuhan §3.2: (a) 0 baris `paid_stock_exception` tanpa keputusan, (b) 0 deadlock 30 menit, (c) tidak ada `payment_status = paid` dengan reservasi `expired` yang belum masuk daftar, (d) smoke test satu transisi order hijau (Doc 10 §9 langkah 4).

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| ≥ 1 order `paid_stock_exception` | penanggung jawab rilis + peran sesuai lane (`sales-ops` RTS, `preorder-ops` PO, `sourcing-ops` request) | ≤ 1 jam; buyer harus dapat kabar sebelum mereka bertanya |
| nominal terancam melewati ambang yang disetujui klien | **klien** + `finance` | sebelum resolusi |
| deadlock berulang / urutan lock dilanggar | penanggung jawab rilis (SEV-1 teknis) | segera; jangan "naikkan lock timeout" |
| stok sudah terlanjur dijanjikan/dikirim (oversell nyata) | `warehouse-staff` + `finance` + **klien**; jalankan `oversell.md` | segera |
| exception queue tidak bisa ditutup karena settlement semantics tidak jelas | **ADR-006 / Q-7** (akun gateway milik klien) | hari kerja berikutnya, dengan daftar pertanyaan konkret |

## 5. Setelah insiden

1. Angka yang harus tercatat: berapa webhook masuk dalam ±5 menit dari `expires_at`, berapa yang gagal reservasi ulang, berapa per opsi resolusi, total nominal rupiah (integer, B-13), dan waktu dari `received_at` sampai keputusan.
2. Keputusan yang harus diuji ulang dari kejadian ini:
   - **TTL reservasi vs expiry payment intent** — Blueprint §8.4 butir 1 minta keduanya **diselaraskan**; kalau data memperlihatkan jendela mismatch, itu temuan desain, bukan operasi. → **ADR-005**.
   - Apakah kebijakan saat `expired` untuk deposit PO (hangus/boleh ulang) sudah benar-benar yang diinginkan klien (Doc 01 §5 U3, B-PO-3).
   - Apakah worker expiry perlu interval lebih pendek atau pembagian per-partisi SKU hot (Blueprint §8.2 Redis lock untuk hot key/scheduler **sebagai optimasi**, bukan penjamin).
3. Test yang wajib menutup celah (Blueprint §16): "Payment webhook vs expiry job race ⇒ **Tidak oversell; late-paid masuk exception/refund policy**", plus test "batch `closing` menolak enrollment baru".
4. Tiket/ADR & isu:
   - **ADR-005** (TTL + late-payment resolution) — dasar keputusan §3.5;
   - **ADR-006** (semantik settlement/refund) — untuk opsi A;
   - isu kode: jalur yang menulis `stock_items`/`stock_reservations` di luar Command (B5), urutan lock tidak konsisten, atau efek diambil sebelum commit (B4);
   - isu kapasitas: `stock_reservations.expires_at` dan jumlah reservasi aktif mendekati kapasitas nyata (Q-4).
5. Perbarui `docs/threat-model.md` T-14/T-36 dan `docs/runbooks/oversell.md` bila ternyata ada resolusi yang diam-diam membuat kita oversell — itu pelanggaran aturan nomor 1 di dokumen ini.
