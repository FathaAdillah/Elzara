# Runbook — Webhook duplikat, out-of-order, atau dipalsukan

**Severity awal:** SEV-2 (duplikat) → **SEV-1** bila `signature_valid = false` atau transaksi asing (skenario Blueprint §15 "duplicate webhook"; aset A-3, A-6; ancaman T-10, T-11, T-12)
**Rujukan:** Blueprint §8.3 (verifikasi signature + merchant ID + amount + currency + reference + status transition; ACK cepat; unique provider event id), §6 (`UNIQUE(payment_provider, provider_event_id)`, `UNIQUE(provider, provider_payment_id)`), §13 ("Verify signed webhooks; secret rotation; replay window; deduplication; allowlist IP **bukan** satu-satunya kontrol"), §16 (test duplicate/out-of-order) · Doc 11 **B-7** (tiga tabel keandalan; `webhook_events` lama → `webhook_receipts`) · Doc 01 §8 (webhook: `X-Signature` diverifikasi secret per driver, payload mentah diarsipkan, endpoint publik hanya POST) · `docs/threat-model.md` T-10/T-11/T-12/T-13/T-21
**Provider-agnostic:** bentuk header, algoritma signature, dan apakah provider mengirim `timestamp`/`nonce` **belum diketahui** sampai **ADR-006 / Q-7**.

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1).

---

## 1. Deteksi

| Sinyal | Sumber | Ambang **(sementara — Q-4)** |
|---|---|---|
| laju `signature_valid = false` per provider | `webhook_receipts` (Doc 11 B-7) | ≥ 1 ⇒ SEV-1 (kemungkinan pemalsuan); ≥ 5/menit ⇒ serangan |
| `provider_event_id` sama diterima ≥ 2× | `UNIQUE(provider, provider_event_id)` menolak → error constraint di log | ≥ 10/jam dari satu IP ⇒ SEV-2 (retry provider normal; tetap dicatat) |
| satu `provider_payment_id` menghasilkan ≥ 2 capture | query §3.2 | ≥ 1 ⇒ **SEV-1** (dedup bocor) |
| `webhook_receipts.status = 'ignored'` melonjak | query §3.3 | > 20% dari total 1 jam ⇒ SEV-2 |
| status uang berubah **mundur** (`captured → pending`) | state machine menolak + error di log (Blueprint §8.3) | ≥ 1 ⇒ SEV-1 (ada jalur tulis di luar aggregate, B5) |
| lonjakan POST ke `/api/v1/webhooks/payments/{provider}` dari IP di luar rentang provider | log edge (Cloudflare/nginx) + origin | ≥ 1 pola baru ⇒ SEV-1 → `data-breach.md` |
| nominal mata uang ≠ intent, atau merchant/account id asing | validasi §8.3 gagal → receipt `ignored` | ≥ 1 ⇒ SEV-1 (payload sah tapi ditujukan ke akun lain) |

## 2. Dampak

Webhook palsu yang diterima = order dianggap lunas tanpa uang → barang dikirim → kerugian riil (integer rupiah, A-3) plus jejak audit yang salah. Webhook duplikat yang tidak tertahan = capture ganda, refund ganda, notifikasi ganda. Keduanya merusak dasar rekonsiliasi harian (U5) dan membuat sengketa tidak bisa dibuktikan.

## 3. Langkah tanggap

### 3.1 Bekukan dampaknya, jangan hapus buktinya

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan down --render=errors.503   # belum diverifikasi di lingkungan ini — pakai HANYA bila capture ganda sedang terjadi aktif
```

`[ verifikasi ]` `payment_status` tidak bertambah salah selama pembekuan; webhook masih **diterima dan diarsipkan** (endpoint tidak boleh dimatikan total — payload yang masuk adalah bukti, dan provider akan me-retry).

> Bila yang terjadi hanya duplikat biasa (retry provider), **jangan** matikan apa pun: dedup sudah menahannya (Blueprint §6, §8.3). Turunkan ke SEV-2 dan lanjut §3.2.

### 3.2 Verifikasi signature dan cari baris receipt-nya

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "
SELECT id, provider, provider_event_id, signature_valid, status, received_at, processed_at, error, intent_id
FROM webhook_receipts
WHERE provider = '<provider>' AND provider_event_id = '<event-id>'
ORDER BY received_at;"                     # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` yang kita pakai sebagai dasar adalah **yang tersimpan**, bukan yang dikirim ulang penyerang: `body` mentah utuh (JSONB) + `headers` + `signature_valid` + `received_at`. Kalau **tidak ada** baris receipt padahal klaim "sudah dikirim" muncul dari sisi provider atau dari tangkapan layar orang — itu bukan webhook duplikat; itu payload yang tidak pernah sampai atau dikirim ke tempat lain. Catat dan lanjut §3.4.

```sql
-- bukti capture ganda (harus kosong)
SELECT provider_payment_id, COUNT(*) AS rows_captured, SUM(amount) AS total_amount
FROM payments
WHERE provider = '<provider>' AND provider_payment_id = '<provider-payment-id>'
GROUP BY provider_payment_id;

-- order yang status uangnya berubah di luar alur
SELECT o.order_number, o.payment_status, osm.occurred_at, osm.reason
FROM orders o
JOIN order_events osm ON osm.order_id = o.id                       -- nama kolom riwayat per Doc 09 §Sales; belum diverifikasi
WHERE o.order_number = '<ELZ-YYMM-NNNNNN>'
ORDER BY osm.occurred_at;
```

`[ verifikasi ]` tepat **satu** baris capture per `provider_payment_id`, dan riwayat transisi hanya bergerak maju (Blueprint §8.3).

### 3.3 Putuskan: suppress atau accept

Uji semua syarat ini terhadap `body` yang tersimpan, bukan terhadap klaim:

| Uji | Bila gagal |
|---|---|
| signature valid dengan secret **environment itu** (staging secret ≠ production) | **suppress** (`ignored`), jangan pernah diproses manual; → §3.4 |
| merchant/account id milik akun kita | **suppress** (payload sah tapi untuk merchant lain) |
| `amount` + `currency` sama dengan `payment_intents` yang dirujuk | **suppress** + alert; jangan pernah "sesuaikan" nominal di DB (B5/M-7) |
| `reference` menunjuk intent yang benar | **suppress**; cek apakah intent sudah `expired` |
| transisi status **maju** dan sah menurut state machine | **suppress** kalau mundur; log alasan |
| event ini yang pertama untuk `(provider, provider_event_id)` | **suppress** sebagai duplikat (no-op) |

**Accept** hanya bila enam-enamnya lulus — dan itu berarti proses normal (ACK cepat + async) yang mengerjakan, bukan kita. Tindakan manusia di sini **hanya** memberi status, tidak pernah menulis uang.

```bash
# bila baris tersangkut di status 'received' padahal lulus semua uji: masalahnya konsumen antrian, bukan webhook
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:status   # belum diverifikasi → lanjut queue-stuck.md §3.3
```

`[ verifikasi ]` `webhook_receipts.status` = `processed` (atau `ignored` dengan `error` berisi alasan uji mana yang gagal), dan `payments`/`payment_ledger` hanya bertambah sesuai jalur normal.

### 3.4 Bila indikasi pemalsuan (SEV-1)

1. **Jangan** matikan endpoint; jangan hapus baris; jangan edit `signature_valid`. Bukti mentah adalah aset.
2. Ambil dan bekukan: `webhook_receipts.body` + `headers` + `received_at`, log edge (IP, `cf-ray`, path), dan metrik laju kegagalan signature. Simpan salinan ke bucket `audit-archives` (restricted — Blueprint §11), **bukan** ke chat atau repo publik (Doc 00 §Batas).
3. Perlakukan secret sebagai **sudah bocor** sampai terbukti sebaliknya: `APP_KEY`, server key gateway, secret per driver. Rotasi **di sisi provider**, bukan sekadar ganti env — aturan ini ditegakkan di `data-breach.md` §3.4.
4. Hentikan sementara setiap aksi yang bisa dieksekusi oleh "pembayaran sah": jangan kirim barang, jangan refund otomatis, matikan jalur pemenuhan manual untuk order terdampak (Blueprint §15 "emergency disable checkout" — `cloudflare-waiting-room.md` §3.6).
5. Rekonsiliasi penuh hari itu: `reconciliation_runs` + `payment_ledger` vs settlement report provider (U5, tidak ada auto-adjust).
6. Cek apakah penyerang masuk **bukan** lewat webhook: `audit_logs` (actor/ip/user_agent/reason), login anomaly, akses bucket. Signature-fail saja tidak membuktikan kunci bocor; bisa jadi mereka hanya mencoba.

`[ verifikasi ]` ada satu orang yang memegang daftar order terdampak + nominal total, dan provider gateway sudah dikonfirmasi bahwa tidak ada transaksi yang kita klaim lunas padahal tidak ada di settlement report mereka.

### 3.5 Idempotency-Key (T-12/T-13) — bagian yang berdekatan dan sering tertukar

Kalau gejalanya "order rangkap dari **klien** kita sendiri" (bukan dari provider):

```sql
SELECT actor_ref, operation, idempotency_key, status, created_at
FROM idempotency_keys
WHERE operation = '<orders.place>'                       -- nilai enum operation dibekukan di kontrak API (B-2); belum diverifikasi
  AND created_at > NOW() - INTERVAL '1 day'
ORDER BY created_at DESC
LIMIT 50;
```

`[ verifikasi ]` jawaban eksplisit salah satu:
- **key sama + payload sama → hasil sama di-replay** (perilaku benar, Blueprint §8.3);
- **key sama + payload beda → 409** dan tidak ada perubahan state (perilaku benar); kalau order tetap terbentuk, middleware idempotensi tidak jalan → isu kode, SEV-1;
- **key milik aktor lain terisi** → cek apakah `actor` diturunkan dari sesi atau dari header yang bisa dikirim klien (threat-model T-13). Kalau dari header, itu eskalasi keamanan, bukan bug biasa.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| satu pun webhook palsu **diproses** (bukan hanya diterima) | penanggung jawab rilis + **klien** + `finance` | segera, SEV-1 |
| indikasi secret gateway/`APP_KEY` bocor | `superadmin` + pemilik akun provider (klien) → `data-breach.md` | segera; rotasi sebelum investigasi selesai |
| trafik dipalsukan dari IP milik kita sendiri / staging menembus produksi | penanggung jawab rilis + `superadmin` (cek env isolation, Blueprint §14.3) | segera |
| provider mengirim event dengan id berbeda untuk transaksi yang sama | `finance` + provider; masuk pertanyaan **ADR-006** | 1×24 jam |
| nominal order terdampak ≥ ambang keputusan klien | **klien** | sebelum resolusi apa pun |

## 5. Setelah insiden

1. Laporan: jumlah event diterima/ditolak per provider, per penyebab uji (signature/merchant/amount/reference/transisi/duplikat), nominal terpengaruh (harus 0 bila dedup bekerja), dan waktu dari `received_at` ke keputusan.
2. Tiket/ADR & isu:
   - **ADR-006**: bentuk signature, `replay window`, ketersediaan `timestamp`/`nonce`, semantik retry per provider;
   - isu: dedup lapis kedua pada `(provider, provider_payment_id, status)` — karena unique per event id tidak menahan provider yang mengirim id baru untuk peristiwa semantik sama (threat-model T-11);
   - isu: `inbox_events` untuk konsumen internal supaya at-least-once aman (Blueprint §8.2);
   - isu: **rotasi berkala secret** + pencatatan kapan (Blueprint §13) — masuk register `data-breach.md` §5;
   - isu: pemisahan endpoint/secret staging vs production (Blueprint §14.3).
3. Test yang harus ada: "Duplicate/out-of-order payment webhook ⇒ satu payment capture record; state valid" (Blueprint §16) dan "replay webhook 3× = 1 transisi" (Doc 01 §11), plus test baru untuk payload dengan `amount`/`currency`/merchant salah.
4. Perbarui `docs/threat-model.md` T-10/T-11/T-12/T-13 (kolom kontrol & sisa risiko) dan `docs/runbooks/payment-callback-late.md` §3.5 bila ternyata kita pernah melakukan re-drive yang tidak aman.
