# Runbook — Rekonsiliasi refund (satu refund per pembayaran sah, tanpa dobel, status money ↔ order sinkron)

**Severity awal:** SEV-1 bila ada indikasi **uang keluar dua kali** atau refund tanpa pembayaran sah; selain itu SEV-2 (rekonsiliasi harian).
**Rujukan:** Blueprint §15 "refund reconciliation", §8.4 butir 4 (late-payment → `paid_stock_exception`: **tidak ada opsi "kirim saja"**), §16 (test "duplicate/out-of-order webhook ⇒ **one capture**") · Doc 11 **B-7** (`webhook_receipts` dedup `(provider, provider_event_id)`; `idempotency_keys` scope `(actor, operation, key)`; `Idempotency-Key` **wajib** untuk order/charge/**refund**), **§8.3** (`payment_status`: `unpaid · partially_paid · paid · overdue · refund_pending · refunded · disputed`; `order_status` memuat `refund_requested · refund_pending · refunded · disputed`), **§8.4** (event `RefundCompleted`, `ReturnDecided`; `PaidStockExceptionRaised` resmi jadi event), §2 (ADR-002 tipe tagihan `deposit | balance | intl_fees | domestic_ship | extra_ship | adjustment`), §4.2 (`returns`/`return_items` — jalur `refund_requested → refunded` bergantung padanya) · `docs/threat-model.md` T-14 (idempotensi) · runbook `payment-callback-late.md`, `webhook-replay.md`, `reservation-expiry-race.md`

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1). **Tiga uang yang dijaga:** (1) tidak ada uang keluar dua kali, (2) tidak ada refund tanpa pembayaran sah, (3) status money (`payment_status`) dan status order (`order_status`) tidak saling bertolak. Nilai status hanya dari Doc 11 §8.3. Kolom nominal/FK (`refunds.status`, `payment_id`, `amount_minor`, dsb.) **perlu dikonfirmasi ke Doc 09** saat dijalankan — query di bawah menandainya `[ verifikasi ]`. **Tidak ada auto-adjust**: selisih dilaporkan ke dashboard eksepsi, bukan di-UPDATE.

---

## 1. Deteksi

| Sinyal | Sumber | Ambang **(sementara — Q-4)** |
|---|---|---|
| Refund macet | `refunds.status` + `payment_status = refund_pending` / `order_status = refund_requested` | menggantung > **(Q-4)** jam ⇒ SEV-2 |
| Refund ganda | `HAVING COUNT(*) > 1` per payment/rule (§3.3) | > 1 untuk satu pembayaran ⇒ **SEV-1** |
| Refund tanpa pembayaran sah | `refunds` join `payments`/`payment_intents` dengan `payment_status NOT IN ('paid','partially_paid')` | ≥ 1 ⇒ SEV-1 (blokir, eskalasi) |
| money ↔ order bertolak | `payment_status = refunded` tapi `order_status` belum, atau sebaliknya | selisih > 0 ⇒ SEV-2 |
| Selisih settlement vs internal | `payment_ledger` vs laporan provider (rekonsiliasi harian) | ≠ 0 ⇒ dashboard eksepsi, jangan sentuh ledger |
| Webhook dobel/urutan kacau | `webhook_receipts (provider, provider_event_id)` duplikat | duplikat > 0 ⇒ cek `webhook-replay.md`; tujuannya **one capture** |

## 2. Dampak

Refund adalah uang keluar; kesalahan di sini tidak bisa "ditambal kemudian" seperti index basi. Refund ganda = rugi riil. Refund tanpa pembayaran sah = potensi fraud/kesalahan data. Dan bila `payment_status` berubah tapi `order_status` tidak (atau sebaliknya), buyer dan staf melihat dua kenyataan berbeda — persis yang dilarang Doc 11 §8.3. `paid_stock_exception` (§3.2) adalah titik paling rawan: stok **sudah** dilepas tapi uang baru datang, dan satu-satunya jalur sah adalah refund/realokasi/manual **dengan alasan tercatat**, bukan "kirim saja" (Blueprint §8.4 butir 4).

## 3. Langkah tanggap

### 3.1 Temukan refund yang macet

```sql
SELECT r.id AS refund_id, r.status AS refund_status, r.payment_id,
       o.id AS order_id, o.order_status, o.payment_status, r.created_at
FROM refunds r
JOIN orders o ON o.id = r.order_id                       -- [ verifikasi ] FK refunds→orders → konfirmasi Doc 09
WHERE r.status NOT IN ('completed','failed','cancelled','succeeded')  -- [ verifikasi ] enum refunds.status → konfirmasi Doc 09
  AND (o.payment_status = 'refund_pending' OR o.order_status IN ('refund_requested','refund_pending'))
ORDER BY r.created_at;
```

`[ verifikasi ]` setiap baris punya `Idempotency-Key` (B-7) — kalau tidak, refund itu berpotensi menghasilkan dua gerakan di provider. Klasifikasikan: (a) masih `refund_requested` (belum disetujui → §3.5 eskalasi), (b) `refund_pending` (disetujui, uang belum masuk → cek provider/webhook), (c) `refunded` di DB tapi provider belum (status bertolak → sinkronkan dari bukti provider, bukan dari tebakan).

### 3.2 Refund untuk `paid_stock_exception` (Blueprint §8.4 butir 4)

Skenario: pembayaran datang **setelah** reservasi kedaluwarsa dan stok dilepas. Yang sah:

1. **Coba reservasi ulang** — hanya bila `available_to_sell` masih cukup. Gagal → tetap eksepsi.
2. **Eksepsi** — order ditandai eksepsi; **event `PaidStockExceptionRaised`** tercatat (Doc 11 §8.4).
3. **Opsi staf: refund / realokasi / manual dengan alasan tercatat.** **Tidak ada opsi "kirim saja"** — mengirim tanpa stok mencuri `reserved` milik orang lain (`oversell.md`, `stock-discrepancy.md`).

```sql
-- daftar eksepsi yang menunggu keputusan, per order
SELECT o.id AS order_id, o.payment_status, o.order_status, o.paid_stock_exception
FROM orders o
WHERE o.paid_stock_exception = true                      -- [ verifikasi ] kolom/tipe paid_stock_exception → konfirmasi Doc 09/B-6
  AND o.payment_status IN ('paid','partially_paid')
ORDER BY o.id;
```

`[ verifikasi ]` tiap eksepsi yang ditutup punya keputusan eksplisit (refund penuh/sebagian, realokasi, atau manual) + **alasan** + actor; refund-nya lewat §3.1/§3.5. Tidak ada jalan pintas "kirim".

### 3.3 Refund ganda — deteksi dan dedup

```sql
-- lebih dari satu refund sah untuk satu pembayaran
SELECT r.payment_id, COUNT(*) AS n_refund, SUM(r.amount_minor) AS total_minor
FROM refunds r
WHERE r.status IN ('completed','succeeded')              -- [ verifikasi ] enum + kolom nominal → konfirmasi Doc 09
GROUP BY r.payment_id
HAVING COUNT(*) > 1;

-- (b) dedup webhook: satu event provider jangan menghasilkan dua gerakan uang
SELECT provider, provider_event_id, COUNT(*) AS n
FROM webhook_receipts
GROUP BY provider, provider_event_id
HAVING COUNT(*) > 1;
```

`[ verifikasi ]` bila §3.3 query pertama mengembalikan baris: itu **SEV-1** — bekukan, jangan refund lagi, eskalasi ke finance + pemilik gateway (klien). Query kedua yang non-kosong berarti webhook dobel **bisa** jadi capture/refund ganda — telusuri ke `webhook-replay.md` dan pastikan idempotensi (`Idempotency-Key`, B-7). Ini bukti langsung untuk test Blueprint §16 "duplicate/out-of-order webhook ⇒ one capture".

### 3.4 Selisih settlement vs internal (rekonsiliasi harian)

Jalankan harian terhadap **`payment_ledger`** (ledger uang internal) dibandingkan settlement provider untuk jendela yang sama. **Tidak ada auto-adjust**: kalau berbeda, tulis ke **dashboard eksepsi** dan tangani manual dengan bukti.

```sql
-- total internal per hari sebagai pembanding laporan provider
SELECT date_trunc('day', pl.created_at) AS hari,
       SUM(pl.amount_minor) AS internal_total_minor,
       SUM(CASE WHEN pl.kind = 'refund' THEN pl.amount_minor ELSE 0 END) AS refund_total_minor
FROM payment_ledger pl
WHERE pl.created_at >= CURRENT_DATE - INTERVAL '2 days'
GROUP BY 1 ORDER BY 1;                                   -- [ verifikasi ] nama kolom payment_ledger (amount_minor/kind/created_at) → konfirmasi Doc 09
```

`[ verifikasi ]` internal_total & refund_total cocok dengan settlement provider dalam batas **(Q-4)**. Selisih → tiket, jangan UPDATE ledger; koreksi = baris pembalik (append-only). Rekonsiliasi uang tertunda yang terseret outage juga muncul di `database-failover.md` §3.5-D.

### 3.5 Refund sebagian vs DP / pelunasan / biaya tambahan (ADR-002)

Tagihan kita bertipe `deposit | balance | intl_fees | domestic_ship | extra_ship | adjustment` (Doc 11 §2 / ADR-002). Refund **sebagian** harus menunjuk **bagian mana** yang dikembalikan — tidak boleh menguras total order tanpa rincian, karena DP (deposit) dan biaya (fees) punya aturan kembali berbeda.

```sql
-- rincian tagihan per order agar refund sebagian dipetakan ke komponen yang benar
SELECT oc.order_id, oc.type AS charge_type, oc.amount_minor
FROM order_charges oc
WHERE oc.order_id = :order_id                            -- [ verifikasi ] order_charges.type enum = tipe ADR-002 → konfirmasi Doc 09
ORDER BY oc.type;
```

`[ verifikasi ]` nominal refund sebagian == jumlah komponen yang **sah dikembalikan** (mis. barang dibatalkan sebelum kirim → balance + fee layanan; barang sudah dikirim → biasanya hanya selisih barang, `intl_fees`/ongkir bisa tidak kembali sesuai kebijakan). Kebijakan mana yang kembali = keputusan bisnis klien, bukan dev.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| Refund ganda terdeteksi (§3.3) | `finance` + pemilik akun gateway (**klien**, ADR-006) | segera (SEV-1) |
| Nominal refund di atas ambang **(Q-4)** | **persetujuan tertulis**; **pemisahan "ajukan vs setujui"** (pengaju ≠ penyetuju) | sebelum eksekusi |
| Kebijakan komponen mana yang dikembalikan / `intl_fees` kembali? | **akunting klien** | sebelum eksekusi |
| Selisih settlement berulang / tak terjelaskan | `finance` + akunting klien + pemilik gateway | 1×24 jam |
| Dugaan refund tanpa pembayaran sah / jejak diubah | `superadmin` + `data-breach.md` | segera |

## 5. Setelah insiden

1. Bekukan angka: jumlah refund, nominal, berapa `paid_stock_exception` ditutup (refund vs realokasi vs manual), selisih settlement tertinggi.
2. **Pastikan event tercatat & diuji (Blueprint §16):** setiap refund/retur yang selesai menghasilkan **`RefundCompleted`** / **`ReturnDecided`** (Doc 11 §8.4); `PaidStockExceptionRaised` tercatat untuk §3.2. Bila event tidak keluar → itu bug outbox (`queue-stuck.md` §3.5), bukan hanya laporan.
3. **Test "duplicate/out-of-order webhook ⇒ one capture":** buktikan lewat `webhook_receipts` (§3.3b) + `idempotency_keys` (B-7) bahwa webhook dobel tidak menghasilkan refund/capture ganda; tambahkan fault injection Blueprint §16 bila belum ada.
4. Tutup rekonsiliasi harian: dashboard eksepsi nol atau punya tiket aktif untuk tiap selisih.
5. Rujukan silang: `payment-callback-late.md` (capture/refund datang terlambat), `webhook-replay.md` (replay webhook pasca-restart), `reservation-expiry-race.md` (asal-usul `paid_stock_exception`).
