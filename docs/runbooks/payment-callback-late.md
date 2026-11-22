# Runbook — Callback pembayaran terlambat / tersangkut

**Severity awal:** SEV-2 → SEV-1 bila melewati TTL reservasi (skenario Blueprint §15 "payment captured but order unpaid"; aset A-3; ancaman T-10/T-11/T-36)
**Rujukan:** Blueprint §8.3 (validasi callback + ACK cepat), §8.4 (payment vs reservation expiry), §15 ("payment webhook delay", runbook wajib), §16 (test "Duplicate/out-of-order payment webhook") · Doc 11 **B-6**, **B-7** · Doc 01 §5 U5 (rekonsiliasi harian, tidak ada auto-adjust) · Doc 09 `reconciliation_runs` / `reconciliation_items` · `docs/threat-model.md` T-10/T-11/T-36
**Provider-agnostic:** nama driver, bentuk header signature, dan tersedia/tidaknya fitur "kirim ulang event" **belum diketahui** sampai **ADR-006 / Q-7** (README §5 butir 1). Prosedur di bawah tidak bergantung pada provider mana pun; bagian yang menyebut portal provider ditulis sebagai langkah manual.

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1).

---

## 1. Deteksi

| Sinyal | Sumber | Ambang **(sementara — Q-4)** |
|---|---|---|
| `payment webhook delay` — selisih `received_at` vs waktu transaksi di sisi provider | metrik Blueprint §15 | p95 > 60 dtk, atau maksimum > 10 menit ⇒ SEV-2 |
| `webhook_receipts.status = 'received'` berumur > 5 menit (ACK tersimpan, proses belum jalan) | query §3.3 | ≥ 5 baris ⇒ SEV-2 (ini masalah **worker**, lihat `queue-stuck.md`) |
| Tidak ada `webhook_receipts` sama sekali padahal intent `requires_action`/`pending` sudah lewat `expires_at` | `payment_intents.expires_at` (Doc 01 §5 U2/U3 + ADR-002) | ≥ 1 ⇒ masuk §3.2 |
| `intent expired vs captured` | dashboard minimum Doc 01 §11 | rasio expired naik tajam 1 jam ⇒ SEV-2 |
| Order `payment_status = unpaid` > 24 h | dashboard Doc 01 §11 | ≥ 1 ⇒ SEV-2; ≥ 10 ⇒ SEV-1 |
| Provider mengirim **report/summary** tapi ledger kita berbeda | rekonsiliasi harian (U5) | selisih ≠ 0 ⇒ SEV-1, masuk §3.5 |
| Horizon antrian `payments` lag / `failed_jobs` bertambah | Blueprint §9, §15 | lihat `queue-stuck.md` |

## 2. Dampak

- **Pemasukan tercatat terlambat** → order kedaluwarsa padahal uang sudah masuk → buyer bayar, barang tidak jalan, dan tim harus menjelaskan.
- Kalau kita "**memaksa** status lunas" tanpa bukti, kita merusak integritas `payment_ledger` (A-3) dan menghapus kemampuan rekonsiliasi (U5) serta dasar pembuktian sengketa.
- Kalau kita biarkan, buyer akan mencoba bayar **dua kali** → risiko double charge yang sebenarnya aman di DB (unique constraint) tapi mahal secara support dan reputasi.
- Bila callback lambat ternyata **tidak** berasal dari provider (tidak ada receipt sama sekali, atau ada receipt dengan `signature_valid = false`), ini bukan insiden keterlambatan tapi indikasi pemalsuan → **hentikan, pindah ke `webhook-replay.md` §3.3 lalu `data-breach.md`**.

## 3. Langkah tanggap

### 3.1 Pisahkan tiga sebab (2 menit, jangan langsung "redrive")

| Sebab | Ciri | Lanjut ke |
|---|---|---|
| **S1 — provider belum mengirim** | tidak ada baris `webhook_receipts` untuk `provider_payment_id` itu | §3.2 → §3.5 |
| **S2 — sudah diterima, prosesnya tersangkut** | ada baris `webhook_receipts` dengan `status = 'received'`/`'failed'`, `signature_valid = true`, `error` terisi | §3.3 → `queue-stuck.md` |
| **S3 — diterima tapi ditolak/diabaikan** | `status = 'ignored'` atau `signature_valid = false` | §3.3 lalu `webhook-replay.md` |

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --since=2h worker | grep -iE "webhook|payment_intent|signature"   # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` salah satu dari S1/S2/S3 terpilih untuk **setiap** order yang dilaporkan, dan tercatat di log insiden (nomor order + nomor intent + sebab).

### 3.2 Tentukan apakah order sudah kedaluwarsa (pertanyaan kunci)

Ada **tiga jam** yang berbeda dan tidak boleh dicampur. Yang menentukan bukan "apa yang tertulis di layar", tapi kombinasi ketiganya:

```sql
-- jam intent, jam reservasi, jam jatuh tempo invoice  (kolom per B-6; belum diverifikasi)
SELECT o.id,
       o.order_number,
       o.payment_status,
       o.fulfillment_status,
       inv.due_date                      AS invoice_due,
       pi.expires_at                     AS intent_expires_at,
       sr.expires_at                     AS reservation_expires_at,
       sr.status                         AS reservation_status,
       NOW() > pi.expires_at             AS intent_passed,
       NOW() > sr.expires_at             AS reservation_passed
FROM orders o
LEFT JOIN invoices inv            ON inv.order_id = o.id
LEFT JOIN payment_intents pi      ON pi.invoice_id = inv.id       -- pengikat intent↔invoice per ADR-002
LEFT JOIN stock_reservations sr   ON sr.order_id = o.id
WHERE o.order_number = '<ELZ-YYMM-NNNNNN>';
```

`[ verifikasi ]` jawaban eksplisit, satu dari empat keadaan ini:

| Keadaan | Intent | Reservasi | Tindakan |
|---|---|---|---|
| **K1** | belum lewat | masih `active` | tunggu/kejar callback (S1/S2), tidak ada perubahan state |
| **K2** | sudah lewat | masih `active` (job expiry belum jalan) | **jangan** expire-kan dulu — selesaikan §3.4, lalu proses pembayaran normal |
| **K3** | sudah lewat | `expired` (stok/kuota sudah dilepas) | **wajib** jalur `paid_stock_exception` — lihat §3.6 + `reservation-expiry-race.md` + `oversell.md` §3.4. **Dilarang** menganggap stok tersedia (Blueprint §8.4 butir 4) |
| **K4** | batch/tagihan pelunasan lewat `due_date` (jalur PO) | n/a (slot kuota sudah/akan dilepas saat tutup batch) | kebijakan deposit per batch (Doc 01 §5 U3, keputusan B-PO-3) — jangan diputuskan sendiri, eskalasi §4 |

### 3.3 Bila receipt sudah ada tapi proses tersangkut (S2/S3)

```sql
SELECT provider, provider_event_id, signature_valid, status, received_at, processed_at, error, intent_id
FROM webhook_receipts
WHERE provider = '<provider>' AND intent_id = (SELECT id FROM payment_intents WHERE code = '<intent-code>')
ORDER BY received_at DESC;
```

`[ verifikasi ]` `signature_valid = true` **sebelum** melangkah ke apa pun yang mengubah state. Kalau `false`: jangan pernah memproses payload itu secara manual — itu jalur pemalsuan (`webhook-replay.md`).

```bash
# jalankan ulang konsumen antrian pembayaran saja (bukan seluruh antrian)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan queue:work \
  --queue=payments --tries=1 --stop-when-empty                  # belum diverifikasi di lingkungan ini
# atau replay satu job dari DLQ
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan queue:retry <failed-job-id>   # belum diverifikasi
```

`[ verifikasi ]` `webhook_receipts.status` berubah `received → processed` dan `payment_status` ikut; **jumlah baris `payments` untuk `provider_payment_id` itu tetap 1** — inilah bukti dedup bekerja:

```sql
SELECT provider_payment_id, COUNT(*) AS capture_rows
FROM payments
WHERE provider = '<provider>'
GROUP BY provider_payment_id
HAVING COUNT(*) > 1;          # harus 0 baris (Blueprint §6 UNIQUE(provider, provider_payment_id))
```

### 3.4 Memicu rekonsiliasi dengan provider

Urutan yang benar, dari yang paling murah:

1. **Tunggu retry provider** bila < 10 menit dan `expires_at` masih panjang (K1). Provider umumnya me-retry; sistem kita sudah tahan duplikat.
2. **Portal provider**: cari transaksi dengan reference/`provider_payment_id` milik kita, baca status + waktu settle. Catat **screenshot/ekspor** hanya ke bucket privat (`invoice-documents`), **jangan** ke repo/chat (Doc 00 §Batas; T-30).
3. **Minta provider mengirim ulang** bila fiturnya tersedia (belum diketahui per provider — ADR-006). Bila tidak tersedia, gunakan jalur manual (langkah 4).
4. **Entri manual ber-bukti**, bukan `UPDATE` (B5/M-7): aksi admin di modul Payments yang menulis entri `payment_ledger` dengan `source = 'manual'` dan **wajib** `evidence_media_id` terisi, serta pemisahan `approved_by <> recorded_by` (aturan A4, Doc 09 §Payments).

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'jalankan job rekonsiliasi untuk tanggal berjalan';"   # belum diverifikasi di lingkungan ini — nama job/command belum ada (belum ada kode)
```

`[ verifikasi ]` baris `reconciliation_runs` baru (`run_date`, `provider`, `expected_total`, `actual_total`, `diff_total`) dibuat dan `reconciliation_items` untuk selisih ini berstatus `open` → `cleared` setelah langkah 4. Tidak ada auto-adjust (U5): `diff_total` boleh bukan nol, tapi setiap butiran punya keputusan manusia yang tercatat.

### 3.5 **Re-drive callback dengan aman** (bagian yang paling sering salah)

Aturan: kita **tidak pernah** mengarang payload webhook. Yang boleh diulang adalah payload **milik provider** yang sudah pernah kita terima, dan itu aman karena:

- `UNIQUE(provider, provider_event_id)` di `webhook_receipts` → pengiriman ulang event yang sama = no-op (B-7, Doc 09 `webhook_once`);
- `UNIQUE(provider, provider_payment_id)` di `payments` → tidak ada capture ganda (Blueprint §6);
- state machine menolak transisi mundur (`paid` tidak kembali `pending`, Blueprint §8.3).

Urutan yang boleh dilakukan (pilih satu, dan hanya satu):

```bash
# Opsi A (diutamakan): ulang dari sisi provider — tidak menyentuh origin kita sama sekali.
# (langkah portal; tidak ada perintah CLI di sisi kita)

# Opsi B: kirim ulang payload MENTAH yang sudah tersimpan, ke endpoint yang sama, TANPA mengubah isinya.
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'replay-from-receipt: ambil body jsonb webhook_receipts.id=<id> dan lewati pipeline verifikasi normal';" \
  # belum diverifikasi di lingkungan ini — perintah/consumer ini BELUM ADA; kalau belum ada, JANGAN pakai curl dengan header signature tiruan
```

`[ verifikasi ]` **ketiganya** harus terbukti sebelum bilang "sudah aman":
1. tidak ada `webhook_receipts` baru untuk `provider_event_id` yang sama (unique menahan);
2. tepat satu baris `payments` untuk `provider_payment_id` itu;
3. satu transisi `order_status_history`/`order_events` baru, bukan dua.

Yang **dilarang**: memakai `APP_KEY`/secret gateway dari env untuk membuat signature sendiri; menempel payload hasil edit "biar cocok"; menaikkan `payment_status` dengan `UPDATE` di `psql`; me-replay payload yang dulu gagal signature.

### 3.6 Kalau ternyata K3 (uang masuk setelah stok/kuota dilepas)

Jangan "coba lagi nanti" tanpa status. Jalur resminya `paid_stock_exception` dengan tiga opsi resolusi (refund / realokasi / manual) — lihat `reservation-expiry-race.md` untuk urutan lock dan `oversell.md` §3.4 untuk matriks keputusan. Prinsip yang tidak bisa dinegosiasi: **kita tidak pernah oversell diam-diam** (Blueprint §8.4 butir 4).

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| status transaksi di sisi provider = **sudah settle**, status kita = belum lunas, dan tidak ada receipt | penanggung jawab rilis + peran `finance`; hubungi provider (akun milik klien → **klien**) | ≤ 30 menit |
| keterlambatan berulang untuk **satu** metode bayar | peran `finance` + keputusan ADR-006 (ganti metode/urut driver?) | hari kerja berikutnya, dengan data |
| keadaan K3 dengan nominal > ambang yang disepakati klien | **klien** + `finance` (approval dual) | sebelum resolusi, bukan sesudah |
| tidak ada `webhook_receipts` tapi trafik webhook di edge tinggi, atau `signature_valid = false` | `superadmin` + jalankan `data-breach.md` | **segera** (SEV-1) |
| buyer mengancam sengketa/payment dispute | `customer-support` + `finance`, simpan bukti | segera; jangan hapus apa pun |

## 5. Setelah insiden

1. Entri log insiden: `run_date`, provider, jumlah intent, nominal rupiah total, pembagian K1–K4, dan waktu dari bayar → tercatat.
2. `reconciliation_items` harus `cleared`/`escalated` — tidak boleh dibiarkan `open` tanpa pemilik.
3. Tiket/ADR:
   - **ADR-006** (gateway & semantik settlement/reconciliation) — daftar pertanyaan konkret dari kejadian ini;
   - **ADR-005** (TTL reservasi + resolusi late-payment) — bila `expires_at` intent dan `stock_reservations.expires_at` ternyata tidak selaras (Blueprint §8.4 butir 1);
   - isu teknis: `webhook_receipts.status` yang tidak bertransisi, ACK yang tidak cepat (Blueprint §8.3), atau handler yang tidak idempoten (Blueprint §9).
4. Test yang wajib ditambahkan (Blueprint §16): "Duplicate/out-of-order payment webhook ⇒ satu payment capture record; state valid", dan "replay webhook 3× = 1 transisi" (Doc 01 §11).
5. Perbarui ambang §1 dari fakta, bukan kepala (Q-4), dan `docs/threat-model.md` T-10/T-11.
