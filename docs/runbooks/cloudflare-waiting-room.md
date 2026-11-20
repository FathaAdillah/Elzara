# Runbook — Cloudflare Waiting Room / admission control salah set, antrean terlalu panjang, atau dilewati

**Severity awal:** SEV-2 → SEV-1 bila antrean memblokir pembeli sah atau origin kebanjiran (ancaman T-20, T-21, T-17, T-34; aset A-10)
**Rujukan:** Blueprint §10.1 (tugas tiap lapisan: CDN, WAF/Bot Management, Rate Limiting, **Waiting Room = admission control**, origin load balancer, app-level queue, database concurrency), §10.2 butir 1–7 (kapasitas dari load test + safety margin; pasang pada rute yang perlu admission dan **pertimbangkan rute checkout dan API agar tidak dapat dilewati secara langsung**; pisahkan halaman cacheable vs privat; dahulukan admitted customers menyelesaikan checkout; halaman antre ber-merek + status page + analytics + load-shedding; **validasi fitur, routing path, session cookies, queue behavior, API bypass, dan pricing di dokumentasi Cloudflare dan plan aktif sebelum implementasi**; webhook tetap lewat rute terpisah + signature + WAF policy + rate limit, **jangan diantrikan sebagai visitor**), §10.3 (fairness: first successful reservation / lottery / FIFO dengan admission ticket dari server; jangan anggap timestamp klien, Redis list sederhana, atau urutan datang HTTP sebagai bukti fairness), §3 "Penting", §15 (metrik admissions & waiting time), §16 (dua test), §19 · Doc 11 **B-8**, §6 **Q-4**, **Q-6** · Blueprint §18 butir 8 → **ADR-009** · `docs/threat-model.md` T-20/T-21/T-17/T-34

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1). Perubahan konfigurasi edge dilakukan di dashboard Cloudflare oleh peran yang memegang akun — **akun edge milik klien** (Q-6).

---

## 0. Kalimat yang harus selalu diingat

**Waiting Room adalah pengendali masuk, bukan penjamin keadilan stok.** Blueprint §10.2: *"Tidak boleh: mengandalkan Waiting Room untuk menjamin first-come-first-served alokasi stok."* Keadilan checkout memerlukan kebijakan order/reservation yang eksplisit (§10.3); di sistem kita yang menegakkan kebenaran adalah conditional UPDATE di Postgres (Blueprint §8.1, §8.5). Kalau ada yang berkata "sudah aman oversell karena ada Waiting Room" — itu salah, dan tugasmu mengoreksinya sebelum insiden lain terjadi.

## 1. Deteksi

| Sinyal | Sumber | Ambang **(sementara — wajib diganti angka Q-4)** |
|---|---|---|
| `waiting-room admissions` & `waiting time` | Cloudflare Analytics + metrik Blueprint §15 | waiting time p50 > **5 menit**, atau antrian > **2×** kapasitas teruji ⇒ SEV-2; > **15 menit** ⇒ SEV-1 |
| Origin RPS / concurrent melewati kapasitas teruji | metrik origin (Doc 01 §11) + Cloudflare | > kapasitas teruji ⇒ SEV-1; threshold ini **tidak boleh** dikarang (Q-4) |
| 5xx/522 dari origin naik saat antrean panjang | Sentry + log nginx; preseden nyata: situs sumber pernah mengembalikan Cloudflare **522** (Doc 08 §1; Doc 01 §12) | ≥ 1% ⇒ SEV-1 |
| Konversi checkout turun drastis sementara RPS naik | dashboard Sales KPI (Blueprint §4) | turun > 30% 1 jam ⇒ SEV-1 (kita menolak pembeli sah, bukan menolak bot) |
| Konversi bot naik: banyak `/checkout` tanpa completion, satu IP banyak akun | WAF/Bot analytics + threat-model T-16/T-17 | pola seragam dari rentang IP sama ⇒ SEV-2 |
| **Bypass**: request ke origin tanpa lewat edge | log origin: tidak ada header `cf-ray`/`cf-connecting-ip`; `cf-cache-status` hilang | ≥ 1 dari IP eksternal ⇒ SEV-1 (test Blueprint §16 baris "Cloudflare bypass") |
| Webhook provider ikut terantrikan Waiting Room | `webhook_receipts` kosong padahal provider mengklaim mengirim; error/timeout di sisi provider | ≥ 1 ⇒ **SEV-1 seketika** (Blueprint §10.2 butir 7: jangan antrekan webhook) |
| Halaman privat ter-cache di edge | header `cf-cache-status: HIT` pada `/me/*`, `/checkout`, `/cart` | ≥ 1 ⇒ SEV-1 → `data-breach.md` (T-34) |

## 2. Dampak

Salah set ke dua arah sama mahalnya. **Terlalu ketat**: pembeli yang sudah admitted tidak bisa menyelesaikan checkout (Blueprint §10.2 butir 4 melarang ini), trafik pindah ke saluran manual, dan reputasi drop saat kampanye. **Terlalu longgar / bisa dilewati**: origin satu node (Doc 11 B-15) tumbang → 522 → semua orang rugi, termasuk yang sedang membayar; dan beban tinggi membuat race stok lebih sering terjadi justru di jam rawan. **Webhook terantri**: pembayaran tidak tercatat tepat waktu → gelombang `payment-callback-late.md` dan `paid_stock_exception`.

## 3. Langkah tanggap

### 3.1 Pastikan dulu ini soal admission, bukan soal correctness

```bash
# Origin masih melayani dengan benar? (cek dari dalam VPS, tidak lewat Cloudflare)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker --execute="echo 'health/uptime check';"   # belum diverifikasi — endpoint health belum ada (belum ada kode)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml ps                                                                        # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --since=15m nginx | grep -cE " 5[0-9]{2} "                          # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` satu keputusan eksplisit: (a) origin sehat, masalahnya **jumlah** orang yang masuk → lanjut §3.2; (b) origin sakit → tangani sebagai insiden kapasitas/kode (`queue-stuck.md`, `rollback-release.md`) dan admission **bukan** solusinya.

### 3.2 Mengubah threshold Waiting Room (di dashboard, bukan di kode)

Langkah (dashboard Cloudflare → Traffic/Waiting Rooms → room terkait):

1. Baca dulu nilai sekarang: **Total Active Users**, **New Users Per Minute**, **session duration**, **bypass on webhook/health path** — catat sebelum mengubah apa pun (tanpa nilai historis, post-mortem tidak mungkin).
2. Nilai baru **tidak boleh** dari kepala. Sumber yang sah: hasil load test k6 di staging = angka kapasitas teruji, dikurangi safety margin (Blueprint §10.2 butir 1). Kalau angka itu belum ada → **Q-4** dan **ADR-009** belum tertutup; maka tindakan sementara yang benar adalah **menurunkan** traffic ke level yang *terbukti* aman, bukan menaikkan.
3. Pastikan cakupan rute: room dipasang pada rute/hostname yang relevan, **termasuk** storefront jalur kampanye/batch PO populer, dan **dipertimbangkan** untuk checkout/API agar tidak bisa dilewati langsung (Blueprint §10.2 butir 2, §3 "Penting").
4. Pastikan **kecualian**: webhook payment (rute terpisah, signature + WAF policy + rate limiting) dan health probes — "secara hati-hati" (Blueprint §3, §10.2 butir 7). Jangan mengecualikan satu hostname penuh; kecilkan ke path.
5. Pastikan admitted users tidak diusir di tengah checkout: atur `session duration`/`allow queue refresh` sesuai dukungan plan (Blueprint §10.2 butir 4) dan jangan men-setting nilai di bawah durasi transaksi nyata (RS/QRIS + redirect gateway).
6. Matikan/aktifkan kembali room untuk kampanye sesuai kebutuhan, bukan "biar menyala terus" kalau tidak diuji — setiap konfigurasi punya perilaku cookie & polling yang harus diuji (Blueprint §10.2 butir 6).

`[ verifikasi ]`
- antrean: `waiting time` p50 turun di bawah ambang §1 tanpa menaikkan 5xx;
- halaman antre tampil ber-merek + status page + analytics hidup (Blueprint §10.2 butir 5);
- **satu pembayaran end-to-end selesai** oleh user yang admitted (bukan hanya "halaman terbuka");
- tidak ada customer yang melihat 5xx karena admission;
- hasil perubahan dicatat di log insiden (nilai lama → nilai baru + dasar angkanya).

```bash
# bukti perubahan terasa di sisi kita (origin), bukan hanya di dashboard
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --since=10m nginx | awk '{print $9}' | sort | uniq -c | sort -rn | head    # belum diverifikasi di lingkungan ini
```

### 3.3 Menguji bypass (test Blueprint §16 "Cloudflare bypass/direct API traffic")

```bash
# A. Lewat edge: harus kena kebijakan admission/WAF/rate limit
curl -s -o /dev/null -D - "https://www.<domain>/api/v1/products?limit=1" | grep -iE "^(HTTP|cf-ray|cf-cache-status|set-cookie)"          # belum diverifikasi di lingkungan ini

# B. Langsung ke IP origin (menghindari Cloudflare): ini yang harus DITOLAK atau dibatasi
curl -s -o /dev/null -w '%{http_code}\n' --resolve "api.<domain>:443:<IP-ORIGIN>" "https://api.<domain>/api/v1/products?limit=1"       # belum diverifikasi di lingkungan ini — <IP-ORIGIN> dari DNS historis/provider VPS, jangan disebarkan

# C. Rate limit origin: tembak N request paralel dan lihat kapan mulai 429
seq 1 200 | xargs -P 20 -I{} curl -s -o /dev/null -w '%{http_code}\n' --resolve "api.<domain>:443:<IP-ORIGIN>" "https://api.<domain>/api/v1/products" | sort | uniq -c   # belum diverifikasi di lingkungan ini — HANYA di staging

# D. Webhook & health: pastikan TIDAK ikut terantri (harus jawaban normal, bukan waiting page)
curl -s -o /dev/null -w 'webhook:%{http_code} ' -X POST "https://api.<domain>/api/v1/webhooks/payments/<provider>" -H 'Content-Type: application/json' -d '{}'
curl -s -o /dev/null -w 'health:%{http_code}\n' "https://api.<domain>/<health-path>"    # belum diverifikasi di lingkungan ini — payload {} sengaja salah; yang diuji adalah TIDAK masuk antrean
```

`[ verifikasi ]`
- B dan C: origin menolak atau membatasi (429/403) **sebelum** sumber daya habis; kalau B sukses 200 tanpa batas → kita punya bypass nyata, dan itu **bukan** tiket cosmetic (T-21).
- D: keduanya **tidak** mengembalikan halaman antre. Webhook yang terantri = `webhook_receipts` kosong = insiden uang.
- C **hanya** di staging; melakukan ini ke produksi = kita sendiri yang melakukan DDoS.

Perbaikan bypass yang sah (urutan prioritas): firewall VPS membatasi ingress ke rentang IP Cloudflare **untuk hostname publik saja** (webhook masuk lewat provider → karena itu signature verification tetap kontrol utama; "allowlist IP **bukan** satu-satunya kontrol", Blueprint §13), rate limit di nginx/origin, dan limit koneksi/pool di aplikasi (Blueprint §8.6). Catatan: pembatasan IP tidak boleh sampai memblokir IP provider webhook — kalau ragu, jangan pakai allowlist, pakai rate limit + signature.

### 3.4 Kalau antrean terlalu panjang dan kita harus memilih yang dilayani

Gunakan **load shedding di origin** (Blueprint §10.2 butir 5), bukan mematikan layanan acak:

```bash
# turunkan beban read-heavy yang tidak memegang transaksi: reindex/search & report export
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:pause --queue=reports   # belum diverifikasi (lihat queue-stuck.md §3.3)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:pause --queue=imports   # belum diverifikasi (lihat queue-stuck.md §3.3)
```

`[ verifikasi ]` antrian `payments`, `inventory`, `orders` **tetap jalan**; 5xx origin turun; status page ter-update. Pemisahan read-heavy dari write-heavy checkout adalah instruksi Blueprint §8.6 — dan itu berarti halaman katalog boleh lambat/lama, checkout tidak.

### 3.5 Kalau yang diminta adalah fairness (drop/flash sale)

Jangan menambah antrean lagi; pilih dan nyatakan satu kebijakan (Blueprint §10.3):

| Kebijakan | Cara kita membuktikan | Yang tidak boleh dipakai sebagai bukti |
|---|---|---|
| first successful reservation | urutan `stock_reservations` + `stock_movements`/ledger + conditional UPDATE (Blueprint §8.1) | timestamp browser; urutan request di log nginx; Redis list sederhana |
| lottery | `drop_claims` unik `(drop_id, account_id)` (Doc 01 §3.1 D6) + hasil undian tercatat + `audit_logs` | "yang pertama WA ke admin" |
| FIFO dengan tiket admission dari server | tiket diterbitkan server + alokasi atomik; kalau butuh FIFO ketat → dedicated allocation service dengan ordered durable requests (Blueprint §10.3) | urutan kedatangan HTTP di edge |

`[ verifikasi ]` kebijakan itu **tertulis** (T&C + layar antre), **dijalankan server-side**, dan ada test yang menjatuhkannya kalau melanggar. Bot defense, per-account limits, dan abuse detection menyertainya (Blueprint §10.3; threat-model T-16).

### 3.6 Emergency disable checkout (Blueprint §15 daftar wajib)

Prioritas: **flag fitur checkout** (System > Feature Flags, Blueprint §4) → matikan hanya mutasi order.

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'set feature flag checkout_enabled=false via Command Settings (idempoten, tercatat)';"   # belum diverifikasi — nama flag & jalur tulis belum ada
# fallback terakhir bila flag belum ada (menghentikan WEBHOOK juga → catat dan pakai < 15 menit):
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan down --render=errors.503   # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` katalog & halaman status tetap bisa dibaca; `POST /orders` dan `/checkout/sessions` tidak lagi membuat order baru; **cart yang sudah ada tidak hilang**; sesi tidak dihancurkan (kalau `down`, jangan `php artisan cache:clear` dan jangan flush Redis — itu membuang sesi & antrean); dan ada satu tiket yang tercatat kapan flag akan dilepas kembali.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| perlu mengubah Waiting Room tapi tidak pegang akun Cloudflare | **klien** (pemilik akun & plan — Q-6) | segera; jangan menunggu |
| bypass terbukti dan origin tidak bisa dibatasi tanpa memutus webhook | penanggung jawab rilis + `superadmin` + klien | **segera (SEV-1)** |
| webhook ikut terantri / hilang | penanggung jawab rilis + **klien/pemilik akun gateway** + jalankan `payment-callback-late.md` | segera (SEV-1) |
| kapasitas teruji belum ada tapi kampanye akan dibuka | **klien** (keputusan jadwal/kampanye) + hasil k6 (**Q-4**) | **sebelum** kampanye, bukan sesudah |
| halaman privat ter-cache / data customer tampak di cache edge | `superadmin` + `data-breach.md` | segera (SEV-1, T-34) |
| keputusan fairness (lottery vs FIFO vs first-reservation) | klien + penanggung jawab rilis | sebelum drop dijadwalkan |

## 5. Setelah insiden

1. Bekukan fakta: nilai konfigurasi sebelum/sesudah + dasar angka, admissions & waiting time puncak, origin RPS puncak vs kapasitas teruji, jumlah request bypass, apakah webhook terdampak, dan berapa checkout selesai vs gagal.
2. Test/DoD yang wajib ditutup (Blueprint §16, §19): "Waiting Room under load ⇒ Origin RPS/concurrency terjaga sesuai kapasitas teruji"; "Cloudflare bypass/direct API traffic ⇒ Origin access policy/rate limits mencegah bypass tak sah"; gerbang "CDN/cache tidak membocorkan data pengguna"; DoD "Cloudflare WAF, rate limiting, Waiting Room, dan origin bypass diuji".
3. Tiket/ADR & isu:
   - **ADR-009** (cakupan rute, threshold, kebijakan bypass) + isi konkret: daftar hostname/path yang diantri, daftar pengecualian, nilai ambang, dan sumber angkanya (Q-4);
   - **Q-6**: konfirmasi fitur Waiting Room & harga pada **plan aktif** dari dokumentasi Cloudflare (Blueprint §10.2 butir 6 minta ini **sebelum** implementasi);
   - isu: jalur webhook dipindah ke hostname/path yang dikecualikan secara sempit;
   - isu: `Cache-Control`/`Vary`/cache key untuk rute berautentikasi (T-34) bila temuan menyangkut cache;
   - isu: status page + load-shedding teruji (Blueprint §10.2 butir 5).
4. Jangan pernah menulis nilai ambang tetap tanpa hasil load test; dan jangan pernah menutup insiden ini dengan kalimat "Waiting Room sudah diperketat" kalau penyebab sebenarnya adalah race stok (lihat `reservation-expiry-race.md`, `oversell.md`).
5. Perbarui `docs/threat-model.md` T-20/T-21/T-17/T-34 dan ambang di `docs/runbooks/queue-stuck.md` §1 bila kapasitas ternyata berbeda dari asumsi.
