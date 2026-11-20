# Runbook — Stok fisik ≠ stok catatan

**Severity awal:** SEV-2 → SEV-1 bila selisih membuat kita sudah menjanjikan barang yang tidak ada (ancaman T-31, T-14; aset A-4)
**Rujukan:** Blueprint §6.1 (model stok: `on_hand`/`reserved`/`damaged`/`in_transit`, `available_to_sell = on_hand − reserved − unavailable`, satu baris agregat per `(warehouse_id, sku_id)` + ledger `stock_movements` **immutable**, hindari double reservation lintas gudang), §4 (menu Inventory: Stock Overview, Reservations, Movements, Transfers, Adjustments, **Stocktake**), §8.1 (conditional UPDATE atomic), §13 (audit untuk stock adjustment), §16 · Doc 11 **B-5**, **B-12**, **B-6** · Doc 09 §Inventory (`stock_movements.reason`, `actor_id`, `reverses_id`) · Doc 10 §5.6 (tabel append-only tidak pernah UPDATE/DELETE) · Blueprint §18 butir 10 → **ADR-011** · `docs/threat-model.md` T-31/T-14

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1). Nama kolom `stock_movements` masih bentuk Doc 09 lama (`variant_id`/`location_id`); penamaan target per Doc 11 §4 adalah `sku_id`/`warehouse_id` — cocokkan dengan hasil rewrite.

---

## 0. Aturan pokok: adjustment tidak pernah senyap

1. Setiap selisih yang kita koreksi = **baris baru** di `stock_movements` dengan `reason` yang benar (`adjustment`, `damage`, `transfer_in`/`transfer_out`, `grn_reversal`), `actor_id` terisi, `occurred_at` jujur, dan `reason`/keterangan di `audit_logs`. **Tidak pernah** `UPDATE` baris lama (S-6, Doc 10 §5.6).
2. Pembalikan koreksi memakai `reverses_id` menunjuk baris yang dibalik — bukan menghapus.
3. Nilai/kuantitas tidak boleh diubah lewat migrasi atau `psql` (M-7, B5). Kalau hanya jalur Command yang bisa menulis, "adjustment senyap" secara mekanis tidak mungkin — dan itu bagian dari perbaikan yang harus dibuktikan.
4. `unavailable` didefinisikan `damaged + quarantined` dan dijaga `CHECK` non-negatif (Doc 11 B-5). Memindahkan barang ke `damaged` adalah tindakan sah; menghapus baris `stock_items` **tidak**.
5. Stok catatan adalah satu-satunya sumber kebenaran (Blueprint §2.2, §8.2): cache dan Elasticsearch tidak boleh dipakai untuk memutuskan "ada atau tidak" (Blueprint §8.6).

## 1. Deteksi

| Sinyal | Sumber | Ambang **(sementara)** |
|---|---|---|
| `stock_items.on_hand` ≠ `SUM(stock_movements.qty_delta)` | invariant terjadwal §3.2 | selisih ≠ 0 ⇒ SEV-2; SKU yang sedang dijual ⇒ SEV-1 |
| `available_to_sell < 0` (CHECK dilanggar / lewat celah) | metrik `oversell_anomalies` (Blueprint §15) | ≥ 1 ⇒ SEV-1 → `oversell.md` |
| `reserved` agregat ≠ jumlah reservasi `active` | query §3.2 butir 3 | ≥ 1 ⇒ SEV-1 (stok terkunci hantu / reservasi lepas tanpa jejak) |
| Selisih hasil stocktake: hitung fisik ≠ catatan | layar Stocktake → `stocktakes` (Blueprint §4, B-5) | apa pun ≠ 0 harus punya alasan & entri |
| `reason = 'adjustment'` melonjak per aktor/hari | query §3.5 | > baseline & tanpa alasan tertulis ⇒ SEV-2, indikasi T-31 |
| `Reservations` lama `active` tanpa order (`order_id` null / order dibatalkan) | Doc 01 §12 | menumpuk ⇒ jalur pelepasan rusak |
| komplain "stok di web ada, di gudang kosong" | `customer-support`/`warehouse-staff` | ≥ 1 ⇒ SEV-1 untuk SKU itu: hentikan penjualannya |

## 2. Dampak

Stok catatan yang salah lebih berbahaya daripada stok yang habis, karena ia dipercaya diam-diam oleh tiga sistem sekaligus: checkout (menjual yang tidak ada), Pre-order (menjanjikan ETA untuk jumlah yang tidak bisa dipenuhi), dan Procurement (memesan ulang berdasarkan angka yang salah, sehingga kerugian bertambah satu siklus impor). Satu selisih kecil pada SKU yang masuk batch bisa menjadi ratusan paket yang tidak bisa dikirim pada saat `GoodsReceived`, dan itu terjadi berminggu-minggu setelah penyebabnya — ketika jejak manual sudah sulit dipisahkan dari jejak sistem.

Dampak kedua bersifat akuntansi dan pembuktian: nominal rupiah write-off (integer, B-13) harus bisa dijelaskan ke `finance` dan ke klien; kalau adjustment-nya senyap, tidak ada yang bisa dijelaskan, dan rekonsiliasi harian kehilangan dasarnya (Doc 01 §5 U5). Dampak ketiga: kepercayaan gudang terhadap sistem. Sekali tim fisik merasa "angka di layar memang tidak benar", stocktake berhenti dilakukan dan selisih berikutnya akan ditemukan oleh customer, bukan oleh kita.

## 3. Langkah tanggap

### 3.1 Hentikan kerugian, bukan penghutangannya

```bash
# berhenti jual SKU terdampak lewat jalur yang sah (bukan mengedit angka stok jadi 0 diam-diam)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'matikan ketersediaan jual SKU via Command Inventory (status tahan/flag)';"   # belum diverifikasi — perintah nyata belum ada
```

`[ verifikasi ]` SKU tidak bisa dibeli baru, **reservasi yang sudah ada tidak dilepas paksa**, dan tidak ada satu pun angka stok yang berubah di langkah ini. Yang berubah hanya kelayakan-jual.

### 3.2 Cari sumber selisih sebelum menyentuh angka

```sql
-- 1) agregat vs ledger
SELECT si.warehouse_id, si.sku_id, si.on_hand, COALESCE(SUM(sm.qty_delta),0) AS ledger_sum,
       si.on_hand - COALESCE(SUM(sm.qty_delta),0) AS selisih
FROM stock_items si
LEFT JOIN stock_movements sm ON sm.sku_id = si.sku_id AND sm.warehouse_id = si.warehouse_id
GROUP BY 1,2,3,5,6
HAVING si.on_hand <> COALESCE(SUM(sm.qty_delta),0);           # belum diverifikasi di lingkungan ini

-- 2) siapa yang bergerak belakangan pada SKU ini
SELECT occurred_at, qty_delta, reason, ref_type, ref_id, actor_id, reverses_id
FROM stock_movements
WHERE sku_id = :sku_id AND warehouse_id = :warehouse_id
ORDER BY occurred_at DESC LIMIT 50;

-- 3) reservasi aktif vs agregat reserved
SELECT si.warehouse_id, si.sku_id, si.reserved,
       COALESCE(SUM(sr.quantity) FILTER (WHERE sr.status = 'active'),0) AS reservasi_aktif
FROM stock_items si
LEFT JOIN stock_reservations sr ON sr.sku_id = si.sku_id AND sr.warehouse_id = si.warehouse_id
GROUP BY 1,2,3
HAVING si.reserved <> COALESCE(SUM(sr.quantity) FILTER (WHERE sr.status = 'active'),0);
```

`[ verifikasi ]` selisih sudah dikategorikan **satu** dari lima sebab: (a) GRN/receipt salah jumlah (`goods_receipts` vs fisik), (b) transfer antar gudang tidak dua-arah (`transfer_in` tanpa `transfer_out` — Blueprint §6.1 melarang double reservation lintas gudang), (c) rusak/quarantine tidak dicatat, (d) kehilangan/pencurian fisik, (e) jalur tulis di luar Command (bug atau manusia — T-31). Kategorinya wajib tertulis di log insiden, karena langkah §3.3–§3.4 berbeda untuk masing-masing.

```sql
-- 4) transfer yang tidak berpasangan (sebab paling sering di multi-location)
SELECT sm.ref_type, sm.ref_id, sm.reason, SUM(sm.qty_delta) AS netto
FROM stock_movements sm
WHERE sm.reason IN ('transfer_in','transfer_out')
  AND sm.occurred_at > NOW() - INTERVAL '14 days'
GROUP BY 1,2,3
HAVING SUM(sm.qty_delta) <> 0;     # belum diverifikasi di lingkungan ini
```

### 3.3 Stocktake (hitung fisik) — prosedur, bukan tebakan

1. **Kunci jendela**: SKU terdampak tidak boleh dibeli/dipindah selama hitung (jalur §3.1). Catat waktu mulai.
2. Hitung **fisik** per `(warehouse_id, sku_id)`, dua orang berbeda, hasil dicatat apa adanya (termasuk yang rusak dan yang masih `in_transit` — jangan dicampur `on_hand`).
3. Masukkan sebagai **stocktake** (tabel `stocktakes`, menu Inventory → Stocktake, Blueprint §4 / B-5): selisih, metode hitung, nama peran penghitung, dan bukti bila ada.
4. Baru setelah selisih tertulis: buat adjustment melalui Command dengan `reason = 'adjustment'` dan keterangan yang menyebut nomor stocktake.
5. Untuk barang rusak/karantina: pindahkan ke `damaged`/`quarantined` (bukan kurangi `on_hand` dengan adjustment generik) supaya `available_to_sell` ikut benar.
6. Untuk barang dalam perjalanan: jangan sentuh `on_hand`; `in_transit` adalah kolom sendiri (Blueprint §6.1).

```bash
# jalankan ulang invariant setelah adjustment masuk
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f db/invariants.sql   # belum diverifikasi — file invariants belum ada; tulis sebagai deliverable test
```

`[ verifikasi ]` query §3.2 butir 1 & 3 **kosong**, dan seluruh selisih kini punya baris ledger yang menjelaskan, bukan hilang.

### 3.4 Kalau penyebabnya GRN / supplier (sambungan skenario "supplier failure")

```sql
SELECT gr.id, gr.status, gri.sku_id, gri.quantity AS dicatat, sm.reason
FROM goods_receipts gr
JOIN goods_receipt_items gri ON gri.goods_receipt_id = gr.id      # nama kolom per rename Doc 11 §4.1, belum diverifikasi
LEFT JOIN stock_movements sm ON sm.ref_type = 'goods_receipt' AND sm.ref_id = gr.id
WHERE gr.received_at > NOW() - INTERVAL '7 days'
ORDER BY gr.received_at DESC;
```

`[ verifikasi ]` setiap GRN punya pasangan `stock_movements` (`reason = 'grn'`) dengan jumlah sama. Selisih GRN dibetulkan lewat **`grn_reversal` + GRN baru**, bukan dengan mengedit GRN lama (append-only). Jalur ini juga menyangkut janji ke buyer Pre-order → koordinasikan dengan `oversell.md` §3.5.

### 3.5 Kalau penyebabnya orang dalam (T-31)

```sql
SELECT a.occurred_at, a.actor_id, a.action, a.target_type, a.target_id, a.reason, a.ip
FROM audit_logs a
WHERE a.action IN ('stock.adjust','stock.damage','price.update','manual_mark_paid')   # nilai enum action per Doc 09 §Audit; belum diverifikasi
  AND a.occurred_at > NOW() - INTERVAL '7 days'
ORDER BY a.occurred_at DESC;

SELECT actor_id, COUNT(*) AS n_adjustment, MIN(occurred_at), MAX(occurred_at)
FROM stock_movements
WHERE reason = 'adjustment' AND occurred_at > NOW() - INTERVAL '30 days'
GROUP BY actor_id ORDER BY n_adjustment DESC;
```

`[ verifikasi ]` tiap adjustment besar punya: alasan tertulis, peran yang tepat, dan (bila menyentuh uang) `approved_by <> recorded_by` (A4). Kalau tidak ada alasan, atau actor-nya tidak sepantasnya punya akses → **jangan hanya menegur**: jalankan `data-breach.md` (pembekuan akun + rotasi + pencabutan sesi) dan simpan `audit_logs` apa adanya. Koreksi catatan stok **tetap** memakai baris baru dengan `reverses_id`; jejak pelaku tidak boleh dihapus.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| selisih membuat barang sudah dijanjikan/dikirim padahal tidak ada | penanggung jawab rilis + `warehouse-staff` + **klien** | segera; jalankan `oversell.md` §3.4 |
| nilai write-off melewati ambang | **klien** + `finance` (approval terpisah) | sebelum adjustment uang dicatat |
| indikasi kehilangan/pencurian atau Insider (T-31) | `superadmin` + klien; `data-breach.md` | segera, jangan konfrontasi sebelum bukti |
| selisih berulang pada gudang yang sama | `warehouse-staff` + keputusan **ADR-011** (alokasi lintas gudang, multi-warehouse Q-5) | 1×24 jam, dengan data 30 hari |
| supplier/GRN selisih sistematis | `procurement-staff` + klien | hari kerja berikutnya |

## 5. Setelah insiden

1. Laporan: daftar SKU, selisih unit, kategori sebab (§3.2), apakah ada order terdampak, nilai rupiah (integer), dan siapa yang menyetujui adjustment.
2. Cegah berulang:
   - invariant §3.2 menjadi **test terjadwal** + alert, bukan query manual;
   - tuliskan sebagai gerbang CI bahwa hanya Command Inventory yang boleh menulis `stock_items`/`stock_movements` (B1/B5) — ancaman T-31 menutup jalur "edit manual";
   - role DB aplikasi tanpa hak `UPDATE`/`DELETE` di tabel append-only (threat-model §6).
3. Tiket/ADR & isu: **ADR-011** (alokasi stok/partial fulfillment/multi-warehouse), isu untuk prosedur stocktake di layar admin (Blueprint §4), isu pasangan `transfer_in`/`transfer_out` mekanis, dan isu GRN reversal.
4. Perbarui `docs/threat-model.md` T-31/T-14 dan `docs/runbooks/oversell.md` bila selisih tadi sempat menghasilkan oversell.
5. Jadwal stocktake preventif (terutama untuk SKU yang dijual di jalur Pre-order, karena kerusakannya menumpuk sampai barang tiba) — catat frekuensinya sebagai keputusan klien, bukan asumsi dev.
