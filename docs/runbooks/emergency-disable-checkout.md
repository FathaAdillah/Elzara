# Runbook — Mematikan checkout secara darurat (emergency disable checkout)

**Severity awal:** **SEV-1 sejak keputusan diambil** — mematikan checkout menghentikan pendapatan, jadi keputusannya tidak pernah teknis sendirian (skenario Blueprint §15 "emergency disable checkout"; aset: seluruh jalur uang & janji stok)
**Rujukan:** Blueprint **§4** (System → **Feature Flags**, tempat flag `checkout.enabled` hidup; nama kanonik di Doc 11 §8.7), **§10.2 butir 7** (jalur admission **tidak mengantrekan webhook** — webhook provider harus tetap bisa masuk), §9 (pool worker terpisah; dahulukan payment callbacks & inventory expiry), §15 (SLO 99.9%, metrik p95 & error rate), §16 (test "Cloudflare bypass / direct API traffic") · Doc 11 §8.2 (runbook nomor 11), §8.1 (tujuh nama antrian kanonik), **B-8** (Waiting Room bukan jaminan fair-stock), **B-15** (satu node = SPOF; tidak ada HA otomatis) · Doc 01 §10 (kontrak API `/api/v1`, problem-details, retry/backoff + DLQ), §11 (dashboard minimum) · **ADR-009** (Waiting Room: cakupan, ambang, bypass — pertanyaan apakah admission bisa menggantikan pembekuan checkout) · pasangan: `oversell.md`, `queue-stuck.md`, `cloudflare-waiting-room.md`, `payment-callback-late.md`, `database-failover.md`, `batch-over-quota.md`

> **Pembeda yang paling penting dari semua runbook lain di folder ini: mematikan checkout BUKAN mematikan webhook.**
>
> Webhook dari gateway/kurir **harus tetap masuk dan tetap diproses**, dan worker `payments` serta `inventory` **harus tetap jalan**. Alasannya mekanis: saat checkout dimatikan, orang yang terlanjur membayar sebelum pembekuan (atau lewat tautan/QR yang sudah terbit) **tetap membayar**. Kalau webhooks atau worker `payments` ikut dimatikan, pembayaran itu tidak pernah tercatat → order tampak `unpaid`/kedaluwarsa → uang ada di rekening tapi tidak ada di sistem. Kalau worker `inventory` ikut dimatikan, reservasi tidak dilepas → stok "terkunci hantu" dan begitu checkout dibuka kembali kita menjual barang yang sama dua kali (lihat `oversell.md`). **Insiden "checkout kami mati" berubah menjadi insiden "uang kami hilang" hanya karena satu perintah `horizon:pause` yang salah sasaran.**

---

## 1. Deteksi

Kapan runbook ini dipakai — pembekuan checkout adalah **respons terhadap salah satu dari ini**, bukan prosedur rutin:

| Sinyal | Sumber | Ambang **(sementara — Q-4)** |
|---|---|---|
| oversell aktif: `available_to_sell` negatif atau penjualan > stok | query `oversell.md` §1; metrik Blueprint §15 "oversell anomaly" | ≥ 1 SKU negatif yang **masih menerima order baru** ⇒ SEV-1, pertimbangkan pembekuan |
| gateway salah capture (dobel, nominal salah, capture tanpa intent) | `webhook_receipts` vs laporan merchant gateway; tiket gateway | ≥ 2 kasus dalam 1 jam, atau satu kasus bernilai besar ⇒ SEV-1 |
| DB/Redis terdegradasi (timeout, replica tertinggal, OOM, eviction) | `database-failover.md`, `queue-stuck.md` §3.6, log | error rate 5xx pada `POST /api/v1/orders` > beberapa % dan **pembayaran tetap diterima saat penulisan state gagal** ⇒ SEV-1 (kondisi paling berbahaya: uang masuk, state tidak) |
| serangan bot pada jalur order/kuota (sniping, pengisian keranjang massal) | WAF/Cloudflare, lonjakan `checkout_sessions` tanpa konversi, `cloudflare-waiting-room.md` §1 | lonjakan teridentifikasi + admission tidak cukup ⇒ SEV-1 (tapi lihat §3.1: **admission lebih dulu**) |
| batch over-quota yang jalurnya masih hidup (D) | `batch-over-quota.md` §3.1–§3.4 | invarian rusak dan masih bertambah ⇒ pembekuan sebagian (jalur preorder) dipertimbangkan |
| p95 checkout & error rate naik serentak saat trafik puncak | metrik Blueprint §15 | sistem tidak lagi sanggup memegang janji stok/ETA ⇒ pertimbangkan pembekuan, **bukan** scaling buta di satu node (Doc 11 B-15) |

## 2. Dampak

**Dampak langsung:** tidak ada revenue baru masuk selama checkout mati; keranjang buyer tertahan; kampanye/iklan yang sedang berjalan mengirim lalu lintas ke dinding; pada batch preorder, jendela `opens_at` tetap berjalan sementara orang tidak bisa mendaftar — itu kehilangan kuota yang tidak bisa dipulihkan.

**Dampak yang harus dicegah justru dengan tetap menghidupkan jalur lain:** order yang sudah dibuat **sebelum** pembekuan tetap harus bisa dibayar, tercatat, dan (kalau kedaluwarsa) dilepas reservasinya. Karena itu cakupan pembekuan harus **sempit dan terverifikasi**: satu jalur tulis (`POST /api/v1/orders`) ditolak, sisanya (webhook, refund, notifikasi, tracking, login, halaman produk) tetap hidup.

Risiko sekunder yang nyata: kalau flag hanya dipasang di sisi storefront sementara API bisa dicapai langsung, kita mendapatkan yang terburuk dari dua dunia — buyer melihat "tutup" di layar tetapi order tetap masuk lewat API (bukti: test Blueprint §16 "Cloudflare bypass/direct API traffic"; lihat §5 butir 4).

## 3. Langkah tanggap

### 3.1 Kenali penyebabnya dulu — dan pastikan admission bukan jawabannya (2–3 menit)

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml ps                              # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:status        # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'BACA DULU: status flag checkout.enabled + laju order per menit';"              # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --tail=200 app \
  | grep -iE "POST /api/v1/orders|500|502|503|timeout|deadlock|connection refused"                 # belum diverifikasi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec redis redis-cli INFO memory | grep -E "used_memory_human|evicted_keys|maxmemory_policy"   # belum diverifikasi
```

```sql
-- laju & nasib order 30 menit terakhir: apakah kita masih menulis state dengan benar?
SELECT date_trunc('minute', created_at) AS menit,
       COUNT(*) AS dibuat,
       COUNT(*) FILTER (WHERE order_status = 'awaiting_payment') AS menunggu_bayar,
       COUNT(*) FILTER (WHERE payment_status = 'paid')           AS sudah_dibayar,
       COUNT(*) FILTER (WHERE order_status IN ('expired','cancelled')) AS mati
FROM orders
WHERE created_at >= NOW() - INTERVAL '30 minutes'
GROUP BY 1 ORDER BY 1 DESC;

-- tanda paling berbahaya: uang diterima padahal penulisan state gagal
SELECT COUNT(*) AS exception
FROM orders
WHERE paid_stock_exception IS NOT NULL AND paid_stock_exception <> 'none';
```

`[ verifikasi ]` angka `dibuat` masih bertambah padahal error rate tinggi = pembekuan dibutuhkan; `dibuat` sudah turun sendiri = cukup runbook penyebabnya. **Pilih cabang penyebab sebelum memutuskan:**

| Penyebab | Jalankan dulu runbook | Checkout dibekukan? |
|---|---|---|
| trafik/bot, sistem sehat tapi kewalahan | `cloudflare-waiting-room.md` (§3 admission **lebih dulu**) | **Tidak dulu.** Admission menggantikan pembekuan untuk kasus ini (ADR-009) |
| antrian/uang tersendat, webhook menumpuk | `queue-stuck.md` §3.2–§3.5 | Hanya bila backlog membuat janji stok/ETA tidak bisa dipegang |
| stok/kuota salah dan masih dijual | `oversell.md`, `batch-over-quota.md` §3.2 (bekukan objeknya, bukan seluruh toko) | **Pembekuan sebagian** diprioritaskan |
| gateway salah capture | `payment-callback-late.md` + `webhook-replay.md`; hubungi gateway | Ya — dan **webhook tetap harus masuk** supaya jejak capture terlacak |
| DB/Redis terdegradasi | `database-failover.md` §3 | Ya — uang tidak boleh masuk ke state yang tidak bisa ditulis |

Catatan: pembekuan **parsial** (hanya preorder, hanya satu lane, hanya satu SKU) selalu lebih baik daripada pembekuan total. Kalau penyebabnya satu objek, bekukan objek itu lewat runbook-nya masing-masing.

### 3.2 Matikan feature flag `checkout.enabled` dan BUKTIKAN dari metrik bahwa ia benar-benar menolak

Flag ini milik Blueprint §4 (System → **Feature Flags**). Yang menentukan adalah **buktinya**, bukan keyflow-nya: flag yang "sudah dinyalakan" tapi `POST /api/v1/orders` tetap 201 adalah pembekuan yang tidak ada.

Tanyakan ke operator **sebelum** bertindak (jangan menjalankan perintah yang tidak kita punya buktinya di repo ini):
- **Di mana flag `checkout.enabled` dibalik** — layar admin Feature Flags, konfigurasi yang dideploy, atau keduanya? Nama layar/CLI-nya **belum terkonfirmasi di lingkungan ini**; gunakan jalur yang sudah dibakukan untuk flag, jangan membuat cara baru di tengah insiden.
- **Apakah flag ditegakkan di sisi API (`api`) atau hanya di storefront (`web`)?** Ini pertanyaan paling menentukan di runbook ini — lihat §3.5 syarat pelepasan dan §5 butir 4.
- Apakah jalur lain ikut kena: `POST /api/v1/orders/{id}/pay`, pembuatan `checkout_sessions`, pembayar keranjang yang sudah ada, dan pendaftaran preorder?

```bash
# verifikasi sisi API: penolakan harus TERLIHAT di log/metrik, bukan diasumsikan
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --since=10m app \
  | grep -E "POST /api/v1/orders" | tail -40                                              # belum diverifikasi di lingkungan ini
# penolakan yang benar juga terlihat di sisi edge/proxy (status 4xx, bukan 5xx):
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --since=10m app \
  | grep -icE "500|502|503"                                                               # belum diverifikasi — pembanding jumlah error selama pembekuan
```

```sql
-- pembekuan yang benar = jumlah order baru berhenti, BUKAN order baru berubah jadi gagal
SELECT date_trunc('minute', created_at) AS menit, COUNT(*) AS dibuat
FROM orders WHERE created_at >= NOW() - INTERVAL '20 minutes'
GROUP BY 1 ORDER BY 1 DESC;
```

`[ verifikasi ]` **empat hal sekaligus**:
1. `dibuat` turun ke **0** (atau ke volume human-only) dalam ≤ 5 menit setelah flag dinyalakan;
2. respons penolakan adalah **problem-details yang terdefinisi** dengan status yang benar (bukan 500 — kalau 500, jalur penolakannya bocor dan buyer melihatnya sebagai kegagalan sistem; kontraknya di Doc 01 §10);
3. pesan publiknya **jujur**: menyebut bahwa pemesanan sedang dihentikan sementara dan **tidak** menyebut waktu buka yang belum pasti;
4. **tidak ada** order baru yang lahir dengan `paid_stock_exception` — kalau masih ada, jalur tulis lain masih terbuka (lihat §3.4 dan `oversell.md`).

Sedot bukti mentahnya untuk arsip: satu tangkapan respons penolakan (URL, status, body problem-details, waktu) dan satu tangkapan dashboard metrik.

### 3.3 Jaga yang TIDAK boleh ikut mati: webhook, worker `payments`/`inventory`, dan sesi yang sedang hidup

Aturan: pembekuan menutup **pintu masuk order baru**. Semua yang mengurus **yang sudah ada** harus tetap jalan.

```bash
# status worker per pool — payments/inventory HARUS tetap Running
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:supervisors   # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:status        # belum diverifikasi di lingkungan ini
```

```sql
-- webhook benar-benar masuk selama checkout mati? (harus TIDAK nol)
SELECT provider, COUNT(*) AS n, MAX(received_at) AS terakhir
FROM webhook_receipts
WHERE received_at >= NOW() - INTERVAL '15 minutes'
GROUP BY provider ORDER BY n DESC;

-- pembayaran yang datang SETELAH checkout mati = bukti jalur uang masih sehat
SELECT COUNT(*) AS dibayar_setelah_pembekuan, MAX(paid_at) AS terakhir
FROM payments
WHERE paid_at >= :waktu_flag_dinyalakan;
```

`[ verifikasi ]` `webhook_receipts` **terus bertambah** (Blueprint §10.2 butir 7: jangan mengantrekan/memblok webhook), dan tiap pembayaran yang masuk setelah pembekuan punya tempat di `payments` + state order yang benar. Nama kolom `payments` (`paid_at`?) dan `webhook_receipts` dikonfirmasi dari Doc 09 sebelum query dipakai sebagai bukti.

Yang harus dipastikan secara aktif:
- **Worker `payments` dan `inventory` tetap jalan.** Keduanya antrian prioritas pertama (Blueprint §9; `queue-stuck.md` §3.3). Pembekuan checkout **bukan** alasan mem-pause keduanya; yang boleh di-pause justru `reports`/`imports` supaya DB lega selama krisis.
- **Webhook tidak boleh ikut kena Waiting Room / pemblokian edge.** Kalau yang dinyalakan adalah pembatasan Cloudflare, periksa aturan bypass webhook (`cloudflare-waiting-room.md` §3, ADR-009 soal cakupan & bypass). Webhook yang tersangkut di admission = pembayaran hilang tanpa jejak.
- **Jangan hapus `checkout_sessions` / `carts` yang sedang hidup.** Sesi yang masih ada = calon pembeli yang akan kembali begitu checkout dibuka; menghapusnya membuang data (dan membuat kita tidak bisa menghitung berapa banyak orang yang dikunci). Sisi teknisnya penting juga: jejak sesi-lah yang membuktikan apakah kita menahan manusia atau menahan bot (§3.1). Jangan ubah status order apa pun yang sedang `awaiting_payment` — biarkan TTL/`expires_at` yang berlaku yang bekerja seperti biasa, dengan pelepasan reservasi tetap jalan (lihat `batch-over-quota.md` §3.5 untuk kuota hantu).
- **Jalur refund, notifikasi, tracking, login, dan halaman produk tetap hidup.** Kalau salah satunya ikut mati, itu bukan "checkout dibekukan" tapi "situs dilumpuhkan" — cakupan yang tidak disetujui siapa pun (§4).

### 3.4 Umumkan lewat kanal yang sudah ada — tanpa menjanjikan waktu buka

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'BACA DULU: template notifikasi & halaman status yang berlaku saat ini';"   # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` pengumuman keluar lewat **kanal yang sudah dibakukan dokumen kita** — halaman/status publik dan notifikasi berbasis template (Doc 09 `notification_templates`; efek dikirim oleh antrian `notifications` per Blueprint §9) — dan bukan lewat pesan ad-hoc dari akun pribadi. Tanyakan ke operator: **nama halaman status publik dan template mana yang dipakai untuk "pemesanan dihentikan sementara" belum terkonfirmasi di lingkungan ini**; jangan membuat template baru di tengah insiden tanpa jejak `audit_logs`.

Isi pesan, dan yang **tidak** boleh ada di dalamnya:
- Boleh: "pemesanan dihentikan sementara", apa yang harus dilakukan buyer yang **sudah** membayar (transaksi tetap diproses / hubungi CS dengan nomor order), apa yang terjadi pada keranjang yang sudah dibuat (tidak hilang).
- **Jangan** menjanjikan waktu buka ("15 menit lagi", "jam 5 sore") sebelum §3.5 syaratnya benar-benar bisa dipenuhi. Janji waktu yang meleset harganya lebih mahal daripada tidak ada janji, dan pada batch preorder ia menjadi janji ETA yang salah (lihat `batch-over-quota.md` §2).
- **Jangan** menyebut nominal stok/kuota internal. Buyer butuh tahu status transaksinya, bukan struktur DB kita.

### 3.5 Lepas pembekuan — bersyarat, dan kalau perlu dibuka sebagian dulu

Jangan melepas flag karena "sudah capek mati". Setiap syarat di bawah punya bukti angkanya:

```sql
-- (1) tidak ada lagi jejak uang tanpa state selama 15 menit terakhir (syarat keras)
SELECT COUNT(*) AS exception_15_menit
FROM orders
WHERE paid_stock_exception IS NOT NULL AND paid_stock_exception <> 'none'
  AND updated_at >= NOW() - INTERVAL '15 minutes';

-- (2) stok & kuota sudah konsisten lagi sebelum pintu dibuka
SELECT COUNT(*) AS stok_negatif
FROM stock_items
WHERE on_hand - reserved - unavailable < 0;

SELECT COUNT(*) AS kuota_over
FROM preorder_batch_items
WHERE reserved_qty > quota;
```

```bash
# (3) antrian uang/stok benar-benar kosong, bukan sekadar pendek
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:status        # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:supervisors   # belum diverifikasi di lingkungan ini
# ukuran kedalaman antrian & DLQ dibaca dari dashboard Horizon. Tanyakan ke operator: perintah CLI mana yang
# dipakai di lingkungan ini untuk membuktikan antrian `payments`/`inventory` kosong dan DLQ bersih — jangan menebak flag
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan migrate:status          # belum diverifikasi — hanya bila pelepasan butuh perbaikan skema
```

`[ verifikasi ]` syarat pelepasan, semuanya terpenuhi dan tercatat jamnya:
1. **Penyebab §3.1 sudah ditutup**, bukan diredam (bukti: query penyebabnya bersih).
2. **Antrian `payments` dan `inventory` kosong / Lag normal**, `failed_jobs` untuk job uang/stok = 0 (bukti dari Horizon dashboard, bukan dari tebakan CLI).
3. **Tidak ada `paid_stock_exception` baru selama 15 menit** terakhir (query 1 = 0).
4. Konsistensi stok & kuota (query 2 = 0 baris).
5. Webhook provider sudah divalidasi ulang dengan replay terkendali bila penyebabnya gateway (`webhook-replay.md`, `payment-callback-late.md`).
6. Flag dimatikan lewat **jalur yang sama** dengan menyalakannya (§3.2), dan **satu order uji** dibuat lebih dulu dari jalur yang sah, terbukti sampai tercatat pembayaran & reservasinya.

**Kalau strategi yang dipilih adalah buka sebagian** (mis. hanya `ready_stock`, preorder/lane impor masih ditahan): pembekuan sebagian harus ditegakkan **di sisi API juga**, bukan hanya menyembunyikan tombol di storefront. Periksa dengan permintaan langsung ke endpoint (lihat §3.2 pertanyaan "flag ditegakkan di mana"): `POST /api/v1/orders` dengan item preorder harus ditolak, item `ready_stock` harus diterima. Tanpa bukti ini, "dibuka sebagian" hanya "dibuka seluruhnya" yang tidak diketahui orang.

**Larangan mutlak saat melepas (dan saat memasang):**

- **Jangan** `DROP`, `TRUNCATE`, `DELETE` besar, atau `REINDEX` untuk "mereset" keadaan. Ledger dan jejak uang harus tetap bisa direkonstruksi apa adanya (S-6: `stock_movements` append-only; outbox append-only — Doc 11 §8.2). Satu-satunya koreksi yang sah = baris/aksi baru yang tercatat (lihat `batch-over-quota.md` §3.4).
- **Jangan** `php artisan horizon:clear` pada antrian `payments`/`inventory`/`orders`, apa pun alasannya. Perintah itu membuang pekerjaan, dan efek sampingnya (invoice/resi, pelepasan stok, notifikasi) tidak pernah jalan — aturan dan pengecualian resminya ada di `queue-stuck.md` §3.3 (`horizon:clear` **hanya** untuk `reports`/`imports` yang idempoten dan bisa diulang aman).
- **Jangan** menghapus/menandai `dispatched_at` pada `outbox_events` supaya "bersih" (`queue-stuck.md` §3.5).
- **Jangan** mem-pause worker `payments`/`inventory` sebagai bentuk "ikut aman" (§3.3).
- **Jangan** membuka kembali sebelum syarat 1–6 terbukti; membuka-then-melihat menghasilkan gelombang `paid_stock_exception` kedua dan membuat durasi mati total dua kali lebih lama.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| **Keputusan mematikan checkout** (parsial maupun total) | **penanggung jawab rilis + klien (pemilik toko)** — persetujuan tertulis; ini menghentikan pendapatan dan tidak pernah menjadi keputusan teknis sendiri | **sebelum** flag dinyalakan, bukan sesudah |
| Keputusan membuka kembali | penanggung jawab rilis (setelah §3.5 terbukti); beri tahu klien kalau penahan masih terpasang | saat pelepasan |
| Pembekuan melewati **15 menit** | `sales-ops` (kampanye/iklan yang masih mengirim trafik ke dinding) + `customer-support` (banjir pertanyaan) | segera, dengan estimasi yang jujur |
| Pembekuan > 1 jam, atau menyentuh batch preorder yang sedang `open` | penanggung jawab rilis + **klien**, karena jendela kuota tetap berjalan sementara pendaftaran mati (lihat `batch-over-quota.md` §2) | dalam 1 jam pertama |
| Sudah ada pembayaran tanpa state / `paid_stock_exception` saat checkout mati | `finance` **dan** `preorder-ops`/`sales-ops` bersama | segera — jalur `payment-callback-late.md` §3.6 + `refund-reconciliation.md` |
| Webhook provider ikut terblokir/tersangkut admission | pemilik akun gateway (**klien** — Q-6/Q-7, Doc 00 Asumsi) + penanggung jawab rilis; buka bypass dulu, baru berdiskusi | **segera — ini darurat di dalam darurat** |
| Flag ternyata hanya ditegakkan di storefront, API masih terbuka | penanggung jawab rilis; tiket ADR-009/ADR-007 (di mana penolakan ditegakkan) | saat itu juga; pertimbangkan penolakan juga di edge |
| Pengumuman publik & kanal status | `customer-support` + klien (nada dan janji waktu ditentukan pemilik toko) | sebelum flag dinyalakan, siapkan teksnya |
| Pembekuan dibutuhkan karena satu node kehabisan sumber daya (B-15) | **klien / pemilik infra** | sebelum tindakan kapasitas, bukan sesudah |

## 5. Setelah insiden

1. **Bekukan durasi nyata** — ini angka utama runbook ini, catat persis: jam flag dinyalakan dan dimatikan, **total menit checkout mati**, jumlah menit per cakupan (total/parsial), jumlah order yang **tidak** terjadi (baseline: rata-rata order/menit dari §3.1 × menit mati), jumlah order yang tertahan di keranjang/sesi, jumlah `paid_stock_exception` yang lahir selama pembekuan (harus 0), jumlah pembayaran yang tetap tercatat dengan benar (bukti jalur uang hidup).
2. **Bekukan alasan & bukti keputusan**: siapa menyetujui (penanggung jawab rilis + klien), syarat §3.5 mana yang terbukti dan jamnya, isi pengumuman publik, serta apakah admission/Waiting Room sudah dicoba lebih dulu untuk penyebab trafik (§3.1).
3. **Tiket wajib:**
   - **"Apakah admission/Waiting Room bisa menggantikan mematikan checkout?"** — tulis sebagai keputusan di **ADR-009** dengan angka dari insiden ini: puncak RPS/concurrent, ambang admission, durasi yang berhasil ditahan, dan durasi pembekuan yang benar-benar terjadi. Ini cara paling langsung memangkas durasi mati di insiden berikutnya (Doc 11 B-8: Waiting Room bukan jaminan fair-stock, tapi ia cukup untuk menahan trafik).
   - **Penegakan flag di sisi API** (bukan hanya storefront) + cakupan pembekuan parsial per `order_type`/lane/jalur — jika §3.2 tidak bisa menjawab "flag ditegakkan di mana", itu tiket, bukan catatan.
   - **Dashboard/gerbang pelepasan**: syarat §3.5 (antrian bersih, `paid_stock_exception` 15 menit = 0) harus bisa dibaca dari dashboard (Doc 01 §11), bukan dari query yang diketik manual di tengah krisis.
   - Ambang §1 diganti angka hasil load test (**Q-4**; k6 — Doc 11 B-11).
4. **Jalankan test Blueprint §16 "Cloudflare bypass / direct API traffic"** — dan perlakukan hasilnya sebagai jawaban langsung atas §3.2: **kalau API bisa dicapai langsung (bypass edge), maka flag di sisi storefront saja tidak cukup** dan pembekuan kita sebenarnya tidak pernah ada. Test ini wajib diulang setiap kali cakupan Waiting Room/edge berubah (Blueprint §16; ADR-009). Tambahkan juga test "checkout dimatikan tetapi webhook tetap diproses" — ia belum ada di daftar §16 dan seharusnya ada, karena justru di situlah pembekuan berubah menjadi kehilangan uang.
5. **Masukkan ke drill**, jangan arsipkan: jalankan skenario pembekuan di `backup-restore-drill.md` §5 sebagai latihan terjadwal (dengan jam nyata), dan sinkronkan pasangan proseduralnya: `cloudflare-waiting-room.md` §3.6 (admission ↔ pembekuan) serta `queue-stuck.md` §3.3 (antrian uang/stok tidak boleh di-`clear`). Runbook yang tidak pernah dilatih akan dibaca ulang sebagai spekulasi saat insiden berikutnya.
6. **Perbarui `docs/threat-model.md`**: pembekuan yang hanya di-client adalah celah integritas (jalur API langsung), bukan sekadar bug UX; webhook yang terblokir admission adalah ancaman ketersediaan untuk jalur uang.
