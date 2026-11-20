# Runbook — Dugaan pelanggaran data / kompromi akun

**Severity awal:** **SEV-1 selalu** sampai terbukti sebaliknya (aset A-1, A-2, A-3, A-5, A-6, A-7, A-11; ancaman T-01, T-02, T-19, T-24, T-26, T-28, T-29, T-30, T-32)
**Rujukan:** Blueprint §13 (MFA admin; RBAC + policy per resource; rate limiting; bot protection; secrets di secret manager/environment protected; **jangan commit `.env`**; **rotasi credential**; audit immutable-ish untuk refund/quote approval/price override/stock adjustment; **redact PII/secrets pada logs**; backup/recovery drills; **Privacy & data retention sesuai regulasi Indonesia yang berlaku; consent dan data subject workflows**; admin dan public dipisahkan secara network/access policy), §12 (admin: BFF, secure HttpOnly SameSite cookies, CSRF, internal/private API routing, IP restrictions/Zero Trust, MFA, separate session policies), §14.3 (environment terpisah: DB, buckets, gateway credentials, domains) · Doc 00 §Batas pekerjaan & §Metode observasi · Doc 01 §8 · Doc 11 §2 (batas KI + metode hanya-baca adalah bagian yang tidak diubah Blueprint) · `docs/threat-model.md` §3, §4, §6

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1).
> **Dua aturan yang tidak boleh dilanggar di dokumen ini:** (1) kredensial yang bocor dirotasi **di sisi provider**, bukan sekadar dihapus dari file/repo/chat; (2) bukti tidak boleh dihapus, diedit, atau "dirapikan".

---

## 1. Deteksi

| Sinyal | Sumber | Ambang |
|---|---|---|
| Lonjakan login gagal + keberhasilan dari banyak IP (stuffing) | log auth + edge (WAF/Bot analytics, Blueprint §13) | ≥ 50 attempt/akun/jam, atau ≥ 5 akun staff kena ⇒ SEV-1 |
| Aksi admin dari IP/`user_agent` yang tidak biasa | `audit_logs.actor_id`, `.ip`, `.user_agent`, `.occurred_at` | ≥ 1 ⇒ SEV-1 |
| Volume ekspor data customer tidak wajar | metrik ekspor + layar Reports/exports | > baseline akun, atau ekspor pertama akun itu ⇒ SEV-1 |
| Signed URL / presigned URL tersebar (email, chat, screenshot, indeks) | laporan eksternal, log edge | ≥ 1 ⇒ SEV-1 (T-24) |
| Bucket privat bisa dibaca publik | hasil cek §3.5 / laporan pihak luar | ≥ 1 objek ⇒ **SEV-1, kebocoran nyata** |
| Kredensial pernah muncul di chat, tiket, atau riwayat repo | review isi repo/CI log/channels | ≥ 1 ⇒ SEV-1 (T-26); rotasi, bukan hapus |
| `webhook_receipts.signature_valid = false` berulang/meningkat | metrik | ≥ 1 ⇒ SEV-1 (T-10) → `webhook-replay.md` |
| Isi `webhook_receipts`/`body` atau payload mengandung PII yang ikut terkirim ke log/APM | sample log | ≥ 1 ⇒ SEV-1 (T-32) |
| Perilaku lintas akun: query `/me/quotations/{id}` milik akun lain (403 tinggi) | log + metrik 4xx per aktor | ≥ 1 pola sistematis ⇒ SEV-1 (T-18) |
| Keluhan phishing ke customer memakai data yang hanya ada di kita (nama+alamat+nomor order) | `customer-support` | ≥ 1 ⇒ SEV-1, anggap data sudah keluar |

## 2. Dampak

Kerugian tidak hanya finansial: alamat + nomor HP penerima barang impor adalah bahan penipuan yang sangat efektif, dan kuotasi privat (A-5) adalah daya tawar bisnis klien. Konsekuensi hukum nyata karena kita memproses data pribadi yang tunduk pada regulasi Indonesia (Blueprint §13), dan karena klien adalah pihak yang akan menghadap regulator/kustomer. Kerusakan kedua: jejak audit yang terhapus membuat sengketa dan klaim refund tidak bisa dibuktikan. Kerusakan ketiga: kredensial provider yang bocor memberi akses ke uang, bukan hanya ke data.

## 3. Langkah tanggap

### 3.1 Menit 0–15: **jangan** menghapus apa pun

Urutan wajib, dan semuanya reversibel:

1. **Bekukan** jalur yang bisa dieksekusi dari luar tanpa mematikan bukti masuk:
   - checkout: matikan mutasi order via **feature flag** (Blueprint §4 System > Feature Flags; prosedur di `cloudflare-waiting-room.md` §3.6) — **bukan** `php artisan cache:clear`, bukan flush Redis;
   - jangan mengirim notifikasi/promosi massal (bisa jadi jalur eksfiltrasi yang sedang dipakai — T-37, `queue-stuck.md`);
   - jangan matikan endpoint webhook: payload yang masuk adalah bukti (lihat `webhook-replay.md` §3.4).
2. **Tunjuk satu orang** sebagai pencatat waktu & keputusan. Semua tindakan ditulis saat itu juga (bukan sesudah) karena nilai buktinya ada pada kronologi.
3. Simpan **salinan** log & artefak ke bucket `audit-archives` (restricted — Blueprint §11):
```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --since=72h > /tmp/incident-app.log        # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs nginx postgres redis minio worker > /tmp/incident-infra.log   # belum diverifikasi — service yang tersedia: Doc 01 §11
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio mc cp /tmp/incident-app.log local/audit-archives/<incident-id>/   # belum diverifikasi
```
4. **Larangan mutlak**: `audit_logs`, `order_events`, `stock_movements`, `payment_ledger`, `tracking_events`, `outbox_events`, `review_moderation` adalah append-only — **tidak pernah** UPDATE/DELETE, koreksi = baris baru dengan `reverses_id` (S-6; Doc 10 §5.6). Termasuk larangan "menghapus nama customer dari log lama" dengan `sed`. Yang sah: batasi akses + catat.

`[ verifikasi ]` ada file log yang bisa dibaca orang lain, ada pemilik insiden, dan tidak satu pun baris tabel jejak berubah selama langkah ini (buktikan dengan `MAX(occurred_at)`/`COUNT(*)` sebelum dan sesudah).

### 3.2 Kenali apa yang sebenarnya kena (lingkup = aset, bukan layar)

```sql
-- 1) aktivitas aktor staff dalam jendela insiden
SELECT actor_id, action, target_type, target_id, reason, ip, user_agent, occurred_at
FROM audit_logs
WHERE occurred_at BETWEEN '<mulai>' AND '<selesai>'
ORDER BY actor_id, occurred_at;

-- 2) akun yang login/berubah kredensialnya
SELECT id, email, email_verified_at, phone, phone_verified_at, updated_at
FROM users
WHERE updated_at BETWEEN '<mulai>' AND '<selesai>'
ORDER BY updated_at;

-- 3) apakah data keluar: ekspor & unduhan
SELECT occurred_at, actor_id, action, target_type, target_id, reason
FROM audit_logs
WHERE action ILIKE '%export%' OR action ILIKE '%download%'
  AND occurred_at > NOW() - INTERVAL '7 days'
ORDER BY occurred_at DESC;                       # belum diverifikasi — enum `action` per Doc 09 §Audit, belum diuji

-- 4) kuota/kuotasi yang disentuh lintas pemilik (percobaan IDOR)
SELECT status, count(*), max(occurred_at) FROM audit_logs
WHERE action ILIKE '%quotation%' GROUP BY status;
```

`[ verifikasi ]` satu kalimat yang bisa diucapkan ke klien: "**aset apa**, **berapa baris/order/akun**, **rentang waktu**, **apakah ada yang keluar**". Kalau belum bisa diucapkan, jangan lanjut ke §3.7 (komunikasi eksternal).

### 3.3 Cabut akses yang sedang dipakai (session invalidation)

Konteks: sesi disimpan di **Redis** (`SESSION_DRIVER=redis`, Doc 01 §11.3; `sessions` tabel tidak dipakai, Doc 09 §16). Karena itu "mencabut sesi" = membuang record sesi, bukan UPDATE tabel. Belum ada perintah terdokumentasi untuk revoke per-user → **butir terbuka README §5 nomor 4 (Q-2 / ADR-007)**, dan sampai ada, jalankan langkah paling kecil yang aman:

```bash
# A. Baca dulu, jangan menulis (identifikasi key sesi milik akun terdampak)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec redis redis-cli --scan --pattern '*session*' | head -50      # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec redis redis-cli DBSIZE                                       # belum diverifikasi

# B. HANYA key milik akun terdampak, bukan pola global
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec redis redis-cli DEL "elzara:session:<session-id>"           # belum diverifikasi — format key belum terverifikasi; JANGAN pakai FLUSHALL/FLUSHDB
```

`[ verifikasi ]` akun yang dibajak tidak lagi bisa membuat mutasi (uji dengan satu request memakai cookie lama ⇒ 401), **dan** jumlah key antrian/cache/rate-limit lain masih utuh (`DBSIZE` tidak berubah drastis), dan user sah tidak dipaksa login ulang massal tanpa keputusan.

Wajib dilakukan setelah itu: ganti password akun terdampak + rotasi secret 2FA + **regenerasi session id** saat login (threat-model T-02, aksi yang belum diimplementasi). Untuk `admin` (sesi & kebijakan terpisah, Blueprint §12) perlakukan setiap akun staff sebagai terdampak bila penyerang punya akses ke jaringan asal.

### 3.4 Rotasi kredensial **di sisi provider** — aturan yang tidak boleh ditawar

Yang wajib dianggap bocor dan dirotasi **di tempat asalnya**, satu per satu, berurutan, lalu dicatat:

| Kredensial | Dirotasi di | Catatan |
|---|---|---|
| Server key gateway / merchant id secret (`MIDTRANS_SERVER_KEY`, `XENDIT_*`, `TRIPAY_*`) | portal provider (akun milik klien — Q-7) | rotasi dulu, uji webhook setelahnya; webhook lama akan gagal signature → itu **benar**, jangan kembalikan secret lama |
| `APP_KEY` (Laravel) | di kita | ganti = sesi/encrypted payload lama tidak terbaca; rencanakan, jangan diam-diam (Doc 01 §11.3) |
| API key kurir (`BITESHIP_KEY`, `RAJAONGKIR_KEY`) | portal kurir | tarif/label sedang berjalan bisa gagal → koordinasikan `warehouse-staff` |
| `WA_GATEWAY_*` | provider WA | bisa dipakai mengirim pesan **atas nama toko** ke seluruh customer → prioritas tinggi |
| Access key MinIO (per service) | konfigurasi MinIO | Blueprint §11 minta key terpisah per service; kalau satu key dipakai semua, rotasi = kesempatan memisahkan |
| Kredensial registry privat / SSH key / akun Cloudflare | provider/infra masing-masing | akses deploy = akses A-1…A-7 |
| Postgres/Redis password | di kita | **Ingat**: rotasi kredensial tidak membuat **dump backup lama** aman (T-28) — cek §3.7 |

**Aturan yang diminta dokumen ini:** kredensial yang ditemukan **di chat, tiket, screenshot, atau riwayat repo** harus **dirotasi/cabut di sisi provider**, bukan "dihapus dari file". Menghapus baris `.env` atau mengedit commit tidak mencabut hak akses apa pun; riwayat repo tetap menyimpan isinya, dan salinan chat pihak lain tetap ada. Setelah rotasi, **ganti env** (Doc 01 §11.3) lalu deploy (Doc 10 §9). Sertakan nomor seri/tanggal rotasi di register §5.

```bash
# cek apakah sesuatu pernah masuk riwayat repo (perintah bersifat READ-ONLY; tidak mengubah git)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker --execute="echo 'verifikasi env terpasang = nilai baru';"   # belum diverifikasi
grep -RniE "server_key|secret|token|password" .env.example                                 # belum diverifikasi — jalankan dari repo copy lokal, dan jangan menempel hasilnya ke dokumen
```

`[ verifikasi ]` provider mengonfirmasi key lama **tidak** aktif (percobaan pakai key lama ditolak), dan satu transaksi uji dengan key baru berhasil di staging dulu, baru produksi.

### 3.5 Bekukan akun staff & cek permukaan akses

```sql
-- nonaktifkan lewat flag/grant, JANGAN hapus baris (jejak & FK harus utuh)
SELECT u.id, u.email, u.status, r.name AS role, mhr.created_at
FROM users u
JOIN model_has_roles mhr ON mhr.model_id = u.id AND mhr.model_type = 'App\Models\User'
JOIN roles r ON r.id = mhr.role_id AND r.guard_name = 'admin'
WHERE u.email = '<akun-terdampak>';

UPDATE users SET status = 'suspended' WHERE id = :user_id;   # BELUM diverifikasi — kolom & jalur penonaktifan harus lewat Command Identity (B1/B5), bukan UPDATE manual
```

`[ verifikasi ]` akun terdampak tidak bisa login, **tidak** kehilangan jejak aksinya, dan tidak menghapus baris apa pun. Kalau penyerang punya akses ke akun `superadmin`: semua role lain dianggap terdampak sampai dibuktikan sebaliknya, dan MFA wajib untuk seluruh staff (Blueprint §13 menyebut MFA admin; perluasan ke semua staff = keputusan klien ⚑ threat-model §6).

Cek permukaan lain yang mungkin jadi jalan masuk/keluar:

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio mc anonymous get local/request-attachments     # belum diverifikasi — lihat object-storage-unreachable.md §3.4
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio mc anonymous get local/invoice-documents        # belum diverifikasi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio mc anonymous get local/shipment-documents       # belum diverifikasi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio mc anonymous get local/exports                   # belum diverifikasi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio mc anonymous get local/product-media             # belum diverifikasi — ini memang publik via CDN; cek TIDAK ada listing & TIDAK ada path privat
curl -sI https://www.<domain>/admin/ | grep -iE "HTTP|cf-cache-status|cache-control"                                        # belum diverifikasi — rute admin harus 401/403 server-side (threat-model T-09), bukan 200 shell
```

### 3.6 Jalur khusus: kode klaim & bukti marketplace (T-29, T-30)

```sql
-- kode yang dilaporkan tanpa kecocokan order, atau berpindah tangan
SELECT cc.status, cc.issued_at, cc.used_at, cc.used_by, cc.verified_by, cc.matched_order_number, cc.void_reason
FROM claim_codes cc
WHERE cc.status IN ('reported','voided') AND cc.updated_at > NOW() - INTERVAL '14 days'
ORDER BY cc.updated_at DESC;              # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` setiap kode yang pernah tampil di luar sistem kita (chat, screenshot, email) **dibatalkan dan diterbitkan ulang** (`voided` + kode baru; kode lama dicatat, tidak dihapus). Karena kode disimpan sebagai hash, kita membatalkan lewat status, bukan lewat pencarian nilai mentah. Bukti (`proof_files`) yang memuat data pihak lain **tidak** diteruskan lewat email/WA: hanya lewat unduhan signed URL pendek yang tercatat (T-30). Bila jalur ini ternyata tidak dipakai klien (B-PO-14, Doc 02), matikan flag `procurement_lane` dan tetap periksa apakah ada bukti yang tersebar.

### 3.7 Kewajiban pemberitahuan & hak data subject

- **Yang menentukan: klien sebagai pemilik bisnis + penasihat hukumnya.** Blueprint §11/§13 mengikat kita pada "regulasi Indonesia yang berlaku" dan pada consent & data subject workflows; dokumen **ini** tidak mengarang pasal, tenggat, atau daftar pihak yang harus diberi tahu. Yang kita sediakan: fakta lengkap (§3.2), waktu, lingkup aset, dan langkah pembatasan yang sudah dilakukan.
- **Jangan** mengirim komunikasi massal ke customer sebelum isinya disetujui klien — komunikasi yang salah memperbesar kerugian dan bisa merusak posisi hukum. Template yang ada (Blueprint §4 Marketing & CMS / Notifikasi) dipakai setelah keputusan, bukan sebagai pengganti keputusan.
- **Data subject request** (akses/hapus) pasca-insiden: proses lewat jalur yang sudah didesain (`users` soft-deletable, S-7; consent & versi yang disetujui, Doc 09 `user_consents` + `content_block_versions`). **Batasan jujur**: hapus di produksi tidak menghapus salinan di dump backup, WAL archive, `audit-archives`, atau log APM (T-28, T-32). Retensi & penghancuran salinan harus diputus dan dicatat; jangan menjanjikan "terhapus di mana pun" kalau tidak bisa dibuktikan.
- **PCI**: kita memperkecil cakupan dengan hosted gateway dan tidak menyimpan raw card details (Blueprint §13). Kalau insiden menyentuh pembayaran, konfirmasi ke provider gateway bahwa tidak ada data kartu yang pernah lewat sistem kita — dan buktikan dengan `webhook_receipts`/`payment_intents`, bukan dengan keyakinan.

### 3.8 Pulihkan dengan uji, bukan harapan

Setelah akses dicabut dan kredensial dirotasi:

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan migrate --force          # belum diverifikasi — hanya bila rilis tertahan (Doc 10 §9)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan horizon:status            # belum diverifikasi
```

`[ verifikasi ]` smoke test (Doc 10 §9 langkah 4) hijau; webhook uji dengan key baru menghasilkan `signature_valid = true`; tidak ada `paid_stock_exception` baru dari kekacauan; matriks otorisasi diuji ulang untuk role yang disentuh; flag checkout dilepas **terakhir**, dengan pemilik keputusan tercatat.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| **semua** dugaan pelanggaran data | penanggung jawab rilis → **klien (pemilik proyek)** + penasihat hukumnya; klien memutuskan notifikasi | menit pertama, bukan setelah analisis selesai |
| kredensial gateway/kurir/WA bocor | pemilik akun provider (**klien**) + `finance` | sebelum rotasi, agar rotasi bisa dieksekusi |
| bucket/backup yang memuat PII bocor | `superadmin` + pemilik infra + klien | segera |
| perlu restorasi dari backup (data dihapus/ubah) | `finance` + klien + jalankan `backup-restore-drill.md` §3.6 | sebelum eksekusi |
| penyerang tampaknya masih aktif di sistem | penanggung jawab rilis + pemilik infra; pertimbangkan `php artisan down` + rotasi total | segera, jangan menunggu bukti lengkap |
| dampak ke customer pihak ketiga (bukti marketplace, data buyer di platform asal) | klien + `customer-support` | segera; kita bukan pihak yang berhak memberi tahu mereka sendirian |

## 5. Setelah insiden

1. Laporan insiden (artefak, bukan niat): kronologi dengan timestamp, vektor masuk, daftar aset terdampak (jumlah baris/order/akun + nominal rupiah), tindakan pembatas, daftar kredensial yang dirotasi (id key, tanggal, provider — **tanpa nilai secret**), dan apa yang masih belum diketahui.
2. Register rotasi kredensial triwulanan + register expiry (domain, sertifikat, akun Cloudflare, akun gateway, template WA) — threat-model T-26/T-38; daftar env yang terdokumentasi = Doc 01 §11.3.
3. Perbaiki penyebab, dengan test:
   - matriks otorisasi per role diuji server-side (Blueprint §19 "role-based access diuji server-side");
   - test "guest vs owner vs staff pada halaman quote privat" (Doc 01 §11) untuk T-18;
   - test header/CSRF/CSP bila vektornya web (T-03, T-04, T-07);
   - secret scanning di CI + larangan `.env` di commit (T-26);
   - scrubber PII di log + retensi (T-32);
   - cek bahwa tidak ada signed URL / payload secret yang tersimpan di log (T-24).
4. ADR/tiket yang kemungkinan harus dibuka: **ADR-007** (model auth & cara revoke sesi — README §5 nomor 4), **ADR-008** (bucket/CDN/TTL), **ADR-006** (semantik gateway), plus keputusan perluasan MFA ke seluruh staff (⚑).
5. `docs/threat-model.md` wajib disegarkan: kolom `kontrol saat ini` & `sisa risiko` untuk T-01/T-02/T-19/T-24/T-26/T-28/T-29/T-30/T-32, ditambah entri baru kalau vektornya tidak ada di tabel.
6. Kalau ada bagian dari insiden ini yang menyentuh **batas kekayaan intelektual** atau data customer pihak ketiga yang tidak seharusnya kita pegang (Doc 00 §Batas; Doc 11 §2; threat-model §4), itu bukan temuan teknis biasa — laporkan terpisah ke klien, karena risikonya hukum, dan itu bagian dari model ancaman ini.
