# Runbook — Anomali oversell / over-quota

**Severity awal:** SEV-1 (kebenaran stok/kuota = aset A-4; ancaman T-14, T-15, T-36)
**Rujukan:** Blueprint §8.1 (atomic reservation), §8.4 butir 4 (`paid_stock_exception`), §8.5 (kuota batch), §15 (metrics + runbook "batch over-quota"), §16 (dua test penerimaan: 1.000 checkout paralel / kuota 50 dengan 500 request) · Doc 11 **B-5**, **B-6**, **B-12**, **B-14** · `docs/threat-model.md` T-14/T-15/T-31/T-36 · Blueprint §18 butir 4 → **ADR-005** (kita), butir 10 → **ADR-011**
**Pemilik:** penanggung jawab rilis + peran `preorder-ops`/`sales-ops`; nilai tulis-ulang = `finance`

> Semua perintah di bawah ditandai **`# belum diverifikasi di lingkungan ini`** — lihat `README.md` §2.1.

---

## 0. Aturan yang tidak boleh dilanggar selama tanggap

1. **Kita tidak pernah oversell secara diam-diam.** Blueprint §8.4 butir 4: kalau pembayaran sah datang setelah stok dilepas, **jangan menganggap stok tersedia** — coba reservasi ulang; bila gagal, tandai `paid_stock_exception` untuk refund/realokasi/resolusi manual. Tidak ada jalur "kirim saja nanti nyambung".
2. **Koreksi stok/kuota tidak pernah berupa `UPDATE` mentah** (B5 + M-7). Semua perubahan lewat Command/aksi admin dan tercatat (S-6, Doc 10 §5.6).
3. **Tidak ada baris yang dihapus** untuk "membersihkan" anomali. Jejak adalah alat kerja kita (`audit_logs`, `stock_movements`, `payment_ledger`).
4. `available_to_sell = on_hand − reserved − unavailable`, dengan `unavailable = damaged + quarantined` dan dijaga `CHECK` non-negatif (Doc 11 B-5). Kalau angka negatif muncul, itu **bukti bug**, bukan kondisi yang boleh "ditambal".

## 1. Deteksi

| Sinyal | Dari mana | Ambang **(sementara — lihat README §2.4, Q-4)** |
|---|---|---|
| `oversell_anomalies` | metrik Blueprint §15 (dihitung dari selisih invariant agregat vs ledger) | ≥ 1 dalam 15 menit ⇒ SEV-1 |
| `CHECK (available >= 0)` / `CHECK(reserved >= 0)` gagal | error PostgreSQL di Sentry/log API | ≥ 1 ⇒ SEV-1 |
| `batch_over_quota` | metrik B-12; layar Dashboard > Alerts (Blueprint §4) — untuk skenario Blueprint §15 "batch over-quota" selengkapnya: **`batch-over-quota.md`** | ≥ 1 enrollment di atas `quota` ⇒ SEV-1 |
| `paid_stock_exception` bertambah | kolom/status pada `orders` (B-6) | lonjakan dibanding baseline harian ⇒ SEV-1 |
| `stock reservation expirations` | metrik Blueprint §15 | > 3× baseline 1 jam ⇒ SEV-2 (awal dari gelombang late-paid) |
| order `payment_status = paid` (capture sudah tercatat — `payment_intents.status`/`payments.status` = `captured`, lihat Doc 11 §8.3) tapi `stock_reservations.status = expired` | query §3.2 | ≥ 1 ⇒ masuk §3.4 |
| DB deadlock / lock waits | metrik Blueprint §15 | retry naik terus ⇒ SEV-2 (gejala urutan lock dilanggar) |
| komplain "sudah bayar tapi tidak dapat barang" | `customer-support` / tiket | ≥ 2 tiket/ jam untuk SKU sama ⇒ SEV-1 |

## 2. Dampak

Oversell berarti kita menjual barang yang tidak kita punya. Untuk Ready to Ship, kerusakannya satu per satu: buyer sudah membayar, barang tidak ada, dan penyelesaian terbaik pun (refund) datang terlambat. Untuk Pre-order/Group Order kerusakannya berganda dan menumpuk sampai barang tiba: kuota batch yang sudah diterima tidak bisa dipenuhi oleh supplier, sehingga satu kesalahan menyebar ke seluruh peserta batch, dan biaya leg internasional yang sudah dijanjikan (landed cost, `intl_fees`) menjadi tidak masuk akal.

Kerugian ikutannya yang sering diremehkan: setiap oversell yang diselesaikan diam-diam menghapus kemampuan kita membuktikan apa pun di sengketa (jejak ada di `stock_movements`/`payment_ledger`/`audit_logs` hanya kalau kita menuliskannya), merusak dasar rekonsiliasi harian (Doc 01 §6.1 U5 + §12, tidak ada auto-adjust), dan membuat stok catatan tidak bisa dipercaya untuk keputusan restock berikutnya. Karena itu urutan dampaknya selalu: kepercayaan pembeli → uang yang harus kembali → kebenaran stok → waktu tim.

## 3. Langkah tanggap

### 3.1 Bekukan permukaan yang bisa memperburuk (≤ 5 menit)

```bash
# berhenti menjual SKU/batch yang terdampak lewat feature flag, BUKAN lewat UPDATE stok
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'inspect feature_flags';"                       # belum diverifikasi di lingkungan ini
# alternatif darurat bila flag per-SKU belum ada: mode perawatan untuk checkout saja
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan down \
  --render=errors.503                                            # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` respons publik `/checkout` dan `POST /orders` = 503; katalog & halaman status tetap hidup; tidak ada order baru masuk selama pembekuan (metrik RPS checkout = 0).

> Catatan: `php artisan down` menghentikan **seluruh** app, termasuk webhook provider. Bila pembekuan lebih dari ~15 menit, webhook akan diretry provider — jangan panik: callback yang **belum pernah** kita terima ditangani `payment-callback-late.md`, sedangkan yang datang **berkali-kali** ditangani `webhook-replay.md` (dedup `UNIQUE(provider, provider_event_id)`). Kalau tersedia, jauh lebih baik memakai kill switch bertingkat (**`emergency-disable-checkout.md`** §3.1–§3.2; pasangan proseduralnya juga dicatat di `cloudflare-waiting-room.md` §3.6) daripada `down`, karena flag menahan **pembuatan order** tanpa menutup jalur callback.

### 3.2 Pastikan ini oversell sungguhan, bukan salah baca

```sql
-- 1) ada baris agregat dengan available negatif?  # belum diverifikasi di lingkungan ini
SELECT warehouse_id, sku_id, on_hand, reserved, damaged, in_transit
FROM stock_items
WHERE on_hand - reserved - unavailable < 0;

-- 2) agregat vs reservation aktif (siapa menahan apa)
--    kolom diikuti Doc 09 §7: stock_items.sku_id (= product_variants.id), stock_reservations.variant_id + qty,
--    status held|committed|released|expired; "menahan stok" = held + committed (lihat uq stock_reservations_once + invariant SUM(held+committed))
SELECT si.warehouse_id, si.sku_id, si.reserved AS aggregate_reserved,
       COALESCE(SUM(sr.qty) FILTER (WHERE sr.status IN ('held','committed')), 0) AS active_reservations
FROM stock_items si
LEFT JOIN stock_reservations sr
       ON sr.variant_id = si.sku_id AND sr.warehouse_id = si.warehouse_id
GROUP BY 1,2,3
HAVING si.reserved <> COALESCE(SUM(sr.qty) FILTER (WHERE sr.status IN ('held','committed')), 0);

-- 3) agregat vs ledger (ledger = bukti, bukan cadangan)
SELECT si.warehouse_id, si.sku_id, si.on_hand, SUM(sm.qty_delta) AS ledger_sum
FROM stock_items si
JOIN stock_movements sm ON sm.sku_id = si.sku_id AND sm.warehouse_id = si.warehouse_id
GROUP BY 1,2,3;      # nama kolom ledger per Doc 09 §Inventory lama (variant_id/location_id) — belum diverifikasi
```

`[ verifikasi ]` salah satu query mengembalikan baris ⇒ oversell nyata (atau discrepancy stok — lanjut `stock-discrepancy.md`); kosong semua ⇒ anomali ada di lapisan pelaporan, turunkan ke SEV-3 dan lanjut §5.

```sql
-- 4) over-quota batch
SELECT pbi.id, pbi.quota, pbi.reserved_qty
FROM preorder_batch_items pbi
WHERE pbi.reserved_qty > pbi.quota;

-- 5) late-paid yang sedang menunggu resolusi
SELECT o.id, o.order_number, o.payment_status, o.paid_stock_exception
FROM orders o
WHERE o.paid_stock_exception IS NOT NULL;                # bentuk kolom/status per B-6, belum diverifikasi
```

### 3.3 Hentikan sumber racunnya (bukan gejalanya)

```bash
# worker antrian stok & pembayaran: cek masih sehat atau sedang mengulang-ulang kegagalan yang sama
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:status   # belum diverifikasi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:supervisors  # belum diverifikasi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --since=30m worker | grep -iE "deadlock|InsufficientStock|available >= 0|reserved_qty"  # belum diverifikasi
```

`[ verifikasi ]` tidak ada lagi baris negatif baru dalam 10 menit setelah §3.1. Kalau masih bertambah, **jangan** perbaiki data dulu — perbaiki jalur tulisnya:

- cari jalur yang menulis `stock_items` / `preorder_batch_items` di luar Command (B1/B5), atau yang membaca-then-menulis tanpa conditional UPDATE (Blueprint §8.1);
- cek apakah `SELECT ... FOR UPDATE` dipakai dengan urutan SKU tidak konsisten (Blueprint §8.1: lock row dalam urutan SKU konsisten; retry deadlock dengan backoff pendek dan batas retry);
- cek apakah Redis dipakai sebagai sumber kebenaran stok — dilarang (Blueprint §8.6; Doc 11 §2).

### 3.4 Resolusi `paid_stock_exception` — pilih **satu** per order, catat pilihannya

Tiga jalur (Blueprint §8.4 butir 4). Keputusan diambil oleh peran yang tertulis, tidak otomatis, dan tidak ada auto-adjust (Doc 01 §6.1 U5 + §12). Untuk jalur A, eksekusi uangnya (refund tercatat, anti dobel payout, kelas eksepsi E1–E5) dilanjutkan di **`refund-reconciliation.md` §3.4E4**.

| Jalur | Kapan dipakai | Cara (harus lewat Command/aksi admin) | Pencatatan wajib |
|---|---|---|---|
| **A. Refund** | barang tidak bisa disediakan dalam batas waktu yang bisa dijanjikan; buyer menolak menunggu | `RefundCommand` (modul Payments) — **bukan** `UPDATE payment_ledger`; provider-agnostic sampai ADR-006 terbit (README §5 butir 1) | entri ledger `source = 'refund'`; `orders.payment_status → refund_pending → refunded`; `order_events` transisi + reason; `audit_logs` dengan `reason` (Doc 01 §8) |
| **B. Realokasi** | stok/varian pengganti tersedia, atau batch lain dengan ETA yang bisa dijanjikan; buyer menyetujui | `Inventory.ReserveStock` ulang pada SKU pengganti → transisi order + perubahan `order_items`; harga/kurs mengikuti **snapshot**, bukan harga hari ini (B-13); persetujuan buyer dicatat (RS-4 Doc 02 bila nominal bergeser melewati ambang ⇒ re-konsen) | `stock_movements` baris baru (bukan edit) dengan `reason = 'adjustment'` + `reverses_id` bila membalik; `audit_logs`; jejak persetujuan buyer |
| **C. Manual / tunggu** | sourcing masih berjalan (PO belum ditutup, supplier gagal — lihat §3.5); nominal tidak berubah | order ditahan di status exception dengan ETA baru + komunikasi; **tidak** boleh berpindah ke tahap `fulfillment_status = ready_to_ship` (Doc 11 §8.3: `ready_to_ship` adalah **tahap lane `import_po`**, bukan `order_status` dan bukan nilai `procurement_status`) sebelum gerbang RS-1 (Doc 02 §11) benar | reason wajib; reminder terjadwal; status exception terlihat di Dashboard > Payments/Alerts + Reports (Blueprint §4; "dashboard exception queue" — Blueprint §8.4 butir 5; pemrosesan uangnya di `refund-reconciliation.md` §3.4E4) |

```bash
# daftar antrean pengecualian yang menunggu keputusan (view pelaporan, read-only — Doc 09 §18)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'lihat layar Dashboard > Payments/Alerts dan Reports';"  # belum diverifikasi
```

`[ verifikasi ]` setiap baris `paid_stock_exception` punya: jalur terpilih, aktor, alasan, dan satu transaksi uang + stok yang selesai (tidak ada order "setengah di-refund, setengah dialokasi").

### 3.5 Kalau sumbernya kegagalan supplier / batch (skenario Blueprint §15 "supplier failure")

```sql
-- batch yang statusnya sudah tidak mungkin menepati kuota terjual
-- nilai preorder_batches.status diikuti Doc 09 §4: draft | open | closed | received | cancelled_by_supplier | archived
-- (kegagalan supplier TIDAK ditulis sebagai status batch — itu fakta `procurement_status = failed`, Doc 11 §8.3)
SELECT pb.id, pb.code, pb.status, pb.order_window_close_at,
       COUNT(*) FILTER (WHERE o.paid_stock_exception IS NOT NULL) AS exceptions
FROM preorder_batches pb
LEFT JOIN orders o ON o.order_type = 'preorder'          -- kolom pengikat batch (batch_id via invoices/enrollments) perlu dikonfirmasi ke Doc 09 §4/§5
WHERE pb.status IN ('closed','received','cancelled_by_supplier')
GROUP BY 1,2,3,4;                                        # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` jumlah buyer terdampak diketahui **sebelum** komunikasi dikirim. Prosedur penuh skenario "supplier failure" sekarang ada di berkas sendiri — **`supplier-failure.md`** (fakta §3.1, empat nasib §3.2, refund massal sebagai **satu** aksi §3.3, komunikasi CS §3.5). Yang tersisa di §3.5 ini hanya identifikasi bahwa sumber eksepsinya supplier/batch. Jangan melakukan refund massal sebelum `finance` menyetujui dan sebelum daftar terdampak dibekukan (bukti di §3.2).

### 3.6 Lepas pembekuan

Bekukan tetap aktif sampai: (a) tidak ada baris negatif baru ≥ 30 menit, (b) semua `paid_stock_exception` punya jalur keputusan, (c) test regresi concurrency ditulis (§5).

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan up   # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` smoke test checkout (login, cari, `checkout` ke driver `manual`, satu transisi order — prosedur smoke ada di Doc 10 §9 langkah 4) hijau; metrik `oversell_anomalies` 0 selama 1 jam.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| oversell nyata pada SKU yang sudah dikirim/dijanjikan ke > 20 buyer | penanggung jawab rilis + **klien (pemilik proyek)** | segera, SEV-1 |
| nilai tulis-ulang stok / penghapusan tagihan | peran `finance` (dual approval, `approved_by <> recorded_by` — preseden A4 Doc 09) | sebelum eksekusi, bukan sesudah |
| indikasi permainan kuota/sybil (T-15/T-16) | `preorder-ops` + `sales-ops`; keputusan aturan di publik **tidak** diumumkan | 1×24 jam |
| indikasi perubahan stok dari dalam (T-31) | `superadmin` + jalankan `data-breach.md` jalur insider | segera; jangan konfrontasi sebelum bukti terkumpul |
| refund massal melewati saldo/limit akun gateway | **klien** (akun gateway milik klien — Q-7) | sebelum eksekusi |

## 5. Setelah insiden

1. Laporan insiden satu halaman: timeline, jumlah order/SKU terdampak, nominal rupiah (integer, B-13), jalur resolusi per order (A/B/C), dan apa yang menghentikan perdarahan.
2. **Wajib** menutup celah dengan test, bukan dengan janji:
   - k6/Testcontainers: "1 SKU, stok 10, 1.000 parallel checkout ⇒ maksimal 10 successful reservations; tidak ada negative available stock" (Blueprint §16);
   - "Batch quota 50, 500 concurrent requests ⇒ maksimal 50 accepted quantity" (Blueprint §16);
   - "Payment webhook vs expiry job race ⇒ tidak oversell; late-paid masuk exception/refund policy" (Blueprint §16) → berkas `reservation-expiry-race.md`.
3. Tiket/ADR yang dibuka:
   - **ADR-005** (reservation TTL + late-payment resolution; Blueprint §18 butir 4, peta Doc 11 §3) — bila belum diterima, resolusi A/B/C belum punya dasar keputusan.
   - **ADR-011** (inventory allocation / partial fulfillment / multi-warehouse; butir 10) — bila realokasi menyentuh lebih dari satu gudang (Q-5).
   - **ADR-006** (gateway & settlement) — bila jalur refund A dipakai nyata.
   - Isu teknis: jalur tulis di luar Command (B5), urutan lock tidak konsisten (Blueprint §8.1), atau `unavailable` tidak dijaga.
4. Perbarui `docs/threat-model.md` baris T-14/T-15/T-36: status kontrol dan sisa risiko setelah kejadian.
5. Perbarui ambang §2 kalau fakta baru menuntutnya — dengan **catatan hasil load test** (Q-4), bukan dengan angka kepala.
