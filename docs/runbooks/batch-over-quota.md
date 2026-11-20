# Runbook — Batch preorder melebihi kuota (batch over-quota)

**Severity awal:** SEV-2 → SEV-1 bila terbukti jumlah enrollment melebihi kuota (pelanggaran invarian) atau sudah ada DP terbayar di atas kuota (skenario Blueprint §15 "batch over-quota"; aset kuota = janji ETA ke buyer)
**Rujukan:** Blueprint **§8.5** (UPDATE kondisional atomic untuk kuota per item batch), **§5.2 langkah 4** (penutupan batch membekukan jumlah accepted), §15 (metrik oversell anomaly), §16 (test "kuota 50 / 500 request paralel") · Doc 11 **B-6** (pagar concurrency menjadi spesifikasi, bukan catatan), **§8.3** (sumbu status kanonik: `fulfillment_status = awaiting_batch`, `order_status = expired`), §8.2 (runbook nomor 4) · **ADR-005 butir 7** (pagar kuota atomic; **nol baris = kuota habis, bukan error**) · Doc 01 §10 (retry deadlock ber-backoff + batas percobaan), Doc 11 §8.1 / Doc 01 §10 (tujuh nama antrian) · Doc 09 (`preorder_batches`, `preorder_batch_items`, `preorder_enrollments`, `preorder_allocations`, `stock_reservations.expires_at`) · pasangan: `oversell.md`, `reservation-expiry-race.md`, `supplier-failure.md`

> Yang dijaga runbook ini **satu angka**: `preorder_batch_items.reserved_qty` tidak boleh melewati `preorder_batch_items.quota`. Pagar kebenarannya adalah satu UPDATE kondisional (Blueprint §8.5 / ADR-005 butir 7):
>
> ```sql
> UPDATE preorder_batch_items
>    SET reserved_qty = reserved_qty + :qty
>  WHERE id = :i AND reserved_qty + :qty <= quota
>    AND status = 'open' AND opens_at <= NOW() AND closes_at > NOW()
> RETURNING id;
> ```
>
> Nol baris kembali = **kuota habis** (jawaban normal, bukan kegagalan). Karena itu "over-quota" tidak mungkin datang dari jalur yang memakai pagar ini: kalau angka di database sudah melebihi kuota, yang salah adalah **jalur tulis yang tidak lewat pagar**.

---

## 1. Deteksi

| Sinyal | Sumber | Ambang **(sementara — Q-4)** |
|---|---|---|
| `reserved_qty > quota` pada salah satu item batch | query §3.1 (invarian) | ≥ 1 baris ⇒ **SEV-1**, ini pelanggaran invarian, bukan anomali |
| `reserved_qty` ≠ jumlah enrollment aktif | query §3.1 pembanding | selisih ≥ 1 baris ⇒ SEV-2; selisih searah "reserved lebih besar" ⇒ kuota hantu (§3.5) |
| `failed_jobs`/DLQ berisi `BatchClosed`, `BatchQuotaExhausted`, constraint CHECK kuota | metrik Blueprint §15; Horizon (Doc 11 §8.1: `orders`) | > 0 dengan exception constraint ⇒ SEV-1 (ada jalur tulis kedua) |
| error rate 5xx / 409 naik pada jam buka batch | metrik Blueprint §15; log API | lonjakan saat `opens_at` ⇒ dugaan balapan `closing` vs enrollment (§3.4) |
| buyer melapor "sudah daftar tapi tidak dapat tempat", atau sebaliknya "diterima padahal kuota penuh" | `customer-support` / keluhan WA | 3 laporan 1 jam pada batch yang sama ⇒ SEV-2 |
| DP terbayar dengan order yang tidak punya `preorder_allocations` | query §3.4 | ≥ 1 baris ⇒ **SEV-1** (uang masuk tanpa kuota) |
| batch berstatus `closing`/`closed` tapi enrollment masih diterima | query §3.1 kolom status | ≥ 1 baris ⇒ SEV-1, TOCTOU cutoff (ADR-005) |

## 2. Dampak

**Over-quota** = kita memesan ke supplier melebihi jumlah yang benar-benar bisa kita penuhi. Rantai konsekuensinya: ETA yang dijanjikan di halaman batch jadi bohong → `procurement_status` bergerak lebih lambat daripada jumlah accepted → selisihnya harus dibayar dengan refund massal atau menunggu PO tambahan dengan harga berbeda (lihat `supplier-failure.md`). Jumlah accepted adalah angka yang dibekukan saat penutupan (Blueprint §5.2 langkah 4) — begitu angkanya salah, seluruh perhitungan keuangan batch ikut salah.

**Under-release** (kuota terkunci padahal barang/peminatnya ada) = kerugian komersial diam-diam: kuota hilang, buyer pindah ke toko lain, dan tidak ada yang melapor karena sistem "sehat-sehat saja".

Empat bentuk kemacetan yang **wajib dibedakan sebelum bertindak** — tindakan yang benar untuk satu bentuk merusak bentuk lain:

| Bentuk | Ciri | Insiden? | Cabang |
|---|---|---|---|
| **A — kuota benar-benar habis** | `reserved_qty = quota`, UPDATE mengembalikan nol baris, tidak ada selisih | **Tidak.** Ini jawaban yang dirancang; dampaknya UX | §3.3 |
| **B — enrollment melebihi kuota** | `reserved_qty > quota` atau jumlah enrollment aktif > `reserved_qty` | **Ya, SEV-1** — invarian rusak, ada jalur tulis tanpa pagar | §3.2 → §3.4 |
| **C — balapan `closing` vs enrollment** | enrollment tercatat setelah/saat batch `closing`; buyer dapat 500/timeout alih-alih `BatchClosed` | Ya, SEV-1 bila lolos pagar; SEV-2 bila hanya UX | §3.2 → §3.4 |
| **D — `reserved_qty` tidak dilepas** | order `expired`/`cancelled` tapi reserved_qty masih menghitung | Ya, SEV-2 — kuota "terkunci hantu" | §3.5 |

## 3. Langkah tanggap

### 3.1 Kenali bentuknya dulu (3–5 menit, hanya-baca)

Jalankan di klien SQL produksi (**hanya SELECT**; tidak ada perintah tulis di langkah ini):

```sql
-- (1) invarian kuota: daftar item yang sudah melewati pagar
SELECT b.id AS batch_id, b.status AS batch_status, b.opens_at, b.closes_at,
       bi.id AS batch_item_id, bi.sku_id, bi.quota, bi.reserved_qty,
       bi.status AS item_status,
       bi.reserved_qty - bi.quota AS lebih_dr_over
FROM preorder_batch_items bi
JOIN preorder_batches b ON b.id = bi.preorder_batch_id
WHERE bi.reserved_qty > bi.quota
ORDER BY lebih_dr_over DESC;

-- (2) pembanding tiga angka: SUM enrollment aktif vs reserved_qty vs quota
SELECT bi.id AS batch_item_id, bi.quota, bi.reserved_qty,
       COALESCE(SUM(pe.qty) FILTER (WHERE pe.status = 'active'), 0) AS enrolled_aktif,
       COUNT(*) FILTER (WHERE pe.status = 'active')                 AS n_enrollment,
       COALESCE(SUM(pe.qty) FILTER (WHERE pe.status = 'active'), 0) - bi.reserved_qty AS selisih_enroll_vs_reserved,
       bi.quota - bi.reserved_qty                                   AS sisa_kuota
FROM preorder_batch_items bi
LEFT JOIN preorder_enrollments pe ON pe.preorder_batch_item_id = bi.id
GROUP BY bi.id, bi.quota, bi.reserved_qty
HAVING bi.reserved_qty <> COALESCE(SUM(pe.qty) FILTER (WHERE pe.status = 'active'), 0)
    OR bi.reserved_qty > bi.quota;

-- (3) batch yang sudah di luar jendela tapi masih menerima enrollment (indikasi B/C)
SELECT b.id, b.status, b.closes_at, COUNT(pe.id) AS enrollment_setelah_close
FROM preorder_batches b
JOIN preorder_batch_items bi ON bi.preorder_batch_id = b.id
JOIN preorder_enrollments pe ON pe.preorder_batch_item_id = bi.id
WHERE pe.created_at >= b.closes_at OR b.status <> 'open'
GROUP BY b.id, b.status, b.closes_at;

-- (4) kuota hantu: reserved_qty dihitung order yang uangnya sudah mati
SELECT bi.id AS batch_item_id, bi.quota, bi.reserved_qty,
       COALESCE(SUM(pe.qty) FILTER (WHERE pe.status = 'active'
             AND o.order_id IS NOT NULL
             AND NOT (o.order_status IN ('expired','cancelled')
                      OR o.payment_status = 'overdue')), 0) AS enrolled_hidup,
       COALESCE(SUM(pe.qty) FILTER (WHERE o.order_status IN ('expired','cancelled')
                 OR o.payment_status = 'overdue'), 0)        AS enrolled_mati_tapi_dihitung
FROM preorder_batch_items bi
JOIN preorder_enrollments pe ON pe.preorder_batch_item_id = bi.id
LEFT JOIN orders o ON o.id = pe.order_id
GROUP BY bi.id, bi.quota, bi.reserved_qty;
```

`[ verifikasi ]` keempat hasil dibekukan ke log insiden **sebelum** tindakan apa pun (angka sebelum dan sesudah harus bisa dibandingkan). Nama kolom yang masih harus dikonfirmasi operator saat menjalankan: `preorder_enrollments.qty`, set nilai `preorder_enrollments.status` (`'active'`?), `preorder_enrollments.order_id`, `preorder_batch_items.status` vs `preorder_batches.status`, dan apakah `orders` punya `preorder_batch_item_id` langsung atau hanya via `preorder_allocations` — semuanya merujuk Doc 09, jangan mengira-ngira. Bentuk cabang: A → §3.3, B/C → §3.2 lalu §3.4, D → §3.5.

### 3.2 Hentikan pendarahan: bekukan batch, jangan tambal angkanya

Tujuan: tidak ada enrollment baru yang masuk sampai penyebabnya jelas. **Ini bukan** izin untuk menulis `reserved_qty` manual.

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'BACA DULU: status batch + jendela buka/tutup, jangan tulis apa pun';"   # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:status      # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:supervisors # belum diverifikasi di lingkungan ini
```

Tanyakan ke operator (jangan jalankan perintah yang belum kita punya buktinya):
- **Bagaimana cara bekukan batch di produksi** — lewat layar admin `preorder_batches.status` / `closes_at`, lewat feature flag, atau lewat perintah khusus? Nama tombol/perintah penutup batch **belum terkonfirmasi di repo ini**; kalau belum ada prosedur bakunya, tutup `closes_at` lebih awal lewat alur admin yang tercatat, bukan lewat SQL.
- Apakah job penutup batch (`CloseBatch`) sedang berjalan atau menumpuk di antrian `orders`? Kalau macet, itu runbook `queue-stuck.md`, dan batch tidak boleh dibekukan sampai outbox-nya terdrainase.

`[ verifikasi ]` `SELECT ... FROM preorder_batches WHERE id = :batch` memperlihatkan status/jendela yang membuat enrollment baru tidak bisa lolos pagar, dan **`reserved_qty` tidak berubah** sejak §3.1. Kalau berubah, masih ada jalur tulis yang hidup — lanjut ke §3.4 dan jangan berhenti di pembekuan.

Larangan mutlak selama insiden:
- **Jangan** `UPDATE preorder_batch_items SET reserved_qty = ...` manual. Angka itu hasil dari pagar atomic; menulisnya langsung menghancurkan satu-satunya bukti tentang apa yang sebenarnya terjadi.
- **Jangan** menghapus baris `preorder_enrollments`. Riwayat pendaftaran = dasar jumlah accepted yang dibekukan (Blueprint §5.2 langkah 4); penghapusan membuat selisih tidak bisa direkonstruksi.
- **Jangan** menutup batch dengan memangkas `quota` menjadi `reserved_qty` yang sudah salah. Itu bukan mengoreksi kesalahan, itu **mendefinisikan ulang janji** ke buyer.

### 3.3 Bentuk A — kuota habis (normal): amankan UX, bukan database

Kalau `reserved_qty = quota` dan tidak ada selisih, **tidak ada insiden data**. Yang rusak hanya harapan buyer.

```sql
-- sisa kuota per item, untuk jawaban publik yang akurat
SELECT bi.id, bi.sku_id, bi.quota, bi.reserved_qty, (bi.quota - bi.reserved_qty) AS sisa,
       p.name_id AS produk, p.slug
FROM preorder_batch_items bi
JOIN product_variants v ON v.id = bi.product_variant_id
JOIN products p ON p.id = v.product_id
WHERE bi.preorder_batch_id = :batch_id;
```

`[ verifikasi ]` `sisa` = 0 untuk item yang dilaporkan penuh, dan tidak ada baris dengan `reserved_qty > quota` dari §3.1. Kalau keduanya benar: tutup penjualan item itu, dan alihkan ke jalur "kabari saya" / `back_in_stock_subscriptions` (dieksekusi antrian `notifications`, tidak menyentuh uang/stok — Doc 11 §4.2). Tanyakan ke `customer-support` pesan publik mana yang dipakai untuk "batch penuh"; jangan menjanjikan batch berikutnya sebelum `supplier-failure.md` / PO benar-benar ada.

### 3.4 Bentuk B dan C — invarian rusak: koreksi lewat tindakan tercatat, bukan UPDATE diam-diam

Sumber yang paling mungkin untuk **B** (jalur tulis di luar pagar atomic):
- `quota` atau `status` item batch diedit langsung/admin **tanpa** melewati Command (Doc 11 §2: Command satu-satunya pintu mutasi) — penurunan pagar;
- job **`CloseBatch`** tidak mengunci baris state batch → **TOCTOU cutoff vs enrollment**: enrollment dibaca "masih `open`", batch ditutup di antara dua statement, lalu enrollment tetap ditulis (ADR-005, Doc 11 §8.3);
- jalur rekon/ledger, impor, atau seed yang menulis `preorder_enrollments`/`reserved_qty` tanpa pagar.

Sumber yang paling mungkin untuk **C**: permintaan yang datang di jendela `closing` **diantre/diretry** alih-alih ditolak. Aturan kanonik (Doc 11 §8.3 / **ADR-005**): permintaan di jendela `closing` harus menerima **`BatchClosed`** (problem-details, tanpa retry), bukan masuk antrian.

Diagnosa jalur mana yang menulis (hanya-baca):

```sql
-- enrollment tanpa jejak pagar: dibuat di luar jendela buka?
SELECT pe.id, pe.preorder_batch_item_id, pe.user_id, pe.qty, pe.status,
       pe.created_at, bi.opens_at, bi.closes_at, b.status AS batch_status
FROM preorder_enrollments pe
JOIN preorder_batch_items bi ON bi.id = pe.preorder_batch_item_id
JOIN preorder_batches b ON b.id = bi.preorder_batch_id
WHERE NOT (b.status = 'open' AND pe.created_at >= bi.opens_at AND pe.created_at < bi.closes_at)
ORDER BY pe.created_at DESC LIMIT 100;

-- audit: siapakah yang menyentuh quota/status batch ini, dan kapan
SELECT id, actor_id, action, subject_type, subject_id, created_at, payload
FROM audit_logs
WHERE subject_type ILIKE '%preorder%' AND created_at >= NOW() - INTERVAL '72 hours'
ORDER BY created_at DESC;
```

`[ verifikasi ]` titik waktu `audit_logs` berimpit dengan kenaikan `reserved_qty`/`quota` yang tidak sah, dan `payload` menunjukkan jalur penulisan yang sama. Nama kolom `audit_logs` (`action`/`subject_type`/`payload`) dikonfirmasi dari Doc 09/Doc 10 sebelum query dipakai sebagai bukti.

Tindakan koreksi yang sah — **urutannya tidak boleh dibalik**:

1. **Bekukan jumlah accepted yang benar** = hasil dari pagar, yaitu enrollment yang punya `preorder_allocations`/DP terbayar dan berada di dalam kuota. Daftar ini ditulis sebagai dokumen koreksi, bukan sebagai UPDATE.
2. **Tandai (jangan hapus) enrollment di atas kuota** dengan status akhir dari enumerasi kanonik Doc 11 §8.3 (`order_status = cancelled` atau `on_hold` untuk kasus yang butuh keputusan finance), melalui **Command/alur admin yang tercatat**, sehingga setiap perubahan meninggalkan baris `audit_logs`. Konfirmasi ke penanggung jawab rilis: **nama Command/alur admin untuk pembatalan enrollment over-quota belum terkonfirmasi di lingkungan ini.**
3. **Refund DP** yang terlanjur masuk untuk bagian di atas kuota: jalankan lewat `refund-reconciliation.md`; jangan refund dari layar gateway saja, karena uang harus bergerak bersama state order (Doc 11 §2, Blueprint §6 Money).
4. **Rekap ke `preorder_allocations`** agar jumlah yang dikirim ke supplier = jumlah accepted yang sah, bukan `reserved_qty` yang salah. Kalau PO sudah terbit, lanjut ke `supplier-failure.md` dengan angka rekap ini.
5. **Kunci penyebab TOCTOU**: pastikan `CloseBatch` mengunci baris batch (`SELECT ... FOR UPDATE`) sebelum mengubah state, dan enrollment yang datang setelahnya ditolak `BatchClosed`. Ini tiket kode, bukan tambal-database.

**Aturan tegas soal menaikkan `quota`** — ini keputusan yang jarang dan hampir selalu salah di tengah insiden:

> **JARANG** menaikkan `quota` langsung di database untuk "meloloskan" peminat yang sudah terlanjur mendaftar. Kuota bukan angka teknis: ia adalah janji ETA dan dasar perhitungan keuangan ke buyer, sekaligus jumlah yang dibekukan saat penutupan (Blueprint §5.2 langkah 4). Menaikkannya diam-diam = mengubah janji tanpa dasar kapasitas nyata.
>
> Dibolehkan **hanya** bila tiga hal terpenuhi sekaligus dan tertulis di log insiden: (a) kapasitas supplier **sudah** dipastikan (bukti: konfirmasi supplier / PO yang bisa diperluas, lihat `supplier-failure.md`), (b) **persetujuan tertulis** penanggung jawab rilis **dan** klien (pemilik toko) — pihak yang menanggung janji ETA, (c) dijalankan lewat alur admin/Command yang menulis `audit_logs`, bukan lewat SQL. Kalau salah satu tidak terpenuhi: kembalikan ke langkah 1–3 (batalkan + refund), jangan naikkan kuota.

`[ verifikasi ]` setelah koreksi: query pembanding §3.1 (2) kembali **nol baris** (`reserved_qty = SUM(enrollment aktif) <= quota`), dan setiap baris yang berubah punya pasangan `audit_logs`. Bila selisih tidak bisa jadi nol, koreksinya belum selesai — jangan ditutup sebagai "sudah dibersihkan".

### 3.5 Bentuk D — kuota terkunci hantu: lepas lewat jalur kedaluwarsa, bukan lewat DELETE

`reserved_qty` yang masih menghitung order `expired`/`cancelled`/`overdue` hampir selalu berarti pelepasan reservasi/kredensial kuota **tidak pernah jalan** — karena worker `inventory` macet (lihat `queue-stuck.md` §3.3, antrian prioritas kedua) atau karena jalur kedaluwarsa tidak melepas dua hal sekaligus (kuota **dan** `stock_reservations`).

```sql
-- order mati yang masih menyandera kuota + reservasi stoknya
SELECT o.id AS order_id, o.order_status, o.payment_status, o.expires_at,
       pe.preorder_batch_item_id, pe.qty AS kuota_masih_dihitung,
       sr.id AS reservation_id, sr.expires_at AS reservation_expires_at
FROM orders o
JOIN preorder_enrollments pe ON pe.order_id = o.id
LEFT JOIN stock_reservations sr ON sr.order_id = o.id
WHERE (o.order_status IN ('expired','cancelled') OR o.payment_status = 'overdue')
  AND pe.status = 'active'
ORDER BY o.expires_at;
```

`[ verifikasi ]` umur baris (`o.expires_at`, `sr.expires_at`) jauh melewati ambang kedaluwarsa (Blueprint §8.3 / **ADR-005**: TTL reservasi + resolusi pembayaran terlambat) dan job pelepasan tetap tidak menjalankan. Sandingkan dengan `payment-callback-late.md`: order yang "kedaluwarsa" tapi uangnya sebenarnya tercapture **tidak boleh** dilepas — itu calon `paid_stock_exception`, dan kuota yang dilepas akan dijual dua kali.

Tindakan:
- **Prioritas pertama = hidupkan kembali jalur pelepasan** (antrian `inventory`, lalu scheduler yang memicu kedaluwarsa). Kalau antriannya macet, kerjakan `queue-stuck.md` §3.2–§3.3 dan kembali ke sini.
- Bila jalur sudah sehat tapi barisnya tetap tersangkut: jalankan **satu siklus** pekerjaan kedaluwarsa secara manual lewat perintah yang sudah ada (tanyakan ke operator: **nama job/perintah pelepasan reservasi & kuota belum terkonfirmasi di lingkungan ini** — jangan membuat artisan command yang tidak ada di repo).
- **Jangan** melepas dengan `UPDATE ... SET reserved_qty = reserved_qty - n` manual, dan **jangan** `DELETE FROM stock_reservations`. Kedua-duanya membuat ledger dan kuota tidak lagi bisa direkonstruksi dari kejadian (S-6: `stock_movements` append-only).
- Setelah kuota benar-benar lepas, buka kembali penjualan item tersebut **hanya** kalau batch masih `open` dan `closes_at` belum lewat; kalau sudah lewat, itu keputusan batch berikutnya (Blueprint §5.2), bukan penyesuaian angka.

`[ verifikasi ]` query §3.1 (2) dan (4) keduanya menghasilkan nol baris menyimpang, `sisa_kuota` naik sebesar jumlah yang memang seharusnya lepas, dan tidak ada `paid_stock_exception` baru di daftar `payment-callback-late.md` §3.4.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| `reserved_qty > quota` (Bentuk B) terkonfirmasi | penanggung jawab rilis (**SEV-1**) + peran `preorder-ops` | segera, sebelum koreksi apa pun ditulis |
| sudah ada **DP terbayar di atas kuota** | `preorder-ops` **dan** `finance` bersama-sama | segera — bagian ini bukan lagi isu data, tapi isu uang (jalur refund: `refund-reconciliation.md`) |
| `quota`/`status` diubah langsung di DB tanpa `audit_logs` | `superadmin` + dugaan manipulasi jejak (`data-breach.md`) | segera; kunci jejak dulu, jangan tambal |
| jumlah yang akan dipesan ke supplier berubah | `preorder-ops` + `procurement-staff` + **klien** (janji ETA ke buyer berubah) | sebelum PO/PO perluasan dibuat, bukan sesudah |
| mengusulkan menaikkan `quota` selama insiden berjalan | penanggung jawab rilis **+ persetujuan tertulis klien** (lihat aturan §3.4) | hanya dengan tiga syarat §3.4 terpenuhi |
| penyebabnya `CloseBatch`/TOCTOU atau worker `inventory` macet | penanggung jawab rilis + tiket kode; bagian queue: `queue-stuck.md` | hari yang sama, karena batch berikutnya akan mengulang |
| buyer sudah diberi ETA yang sekarang tidak realistis | `customer-support` + `sales-ops` | segera, dengan angka rekap §3.4 langkah 4 |

## 5. Setelah insiden

1. **Bekukan angka** di log insiden: `quota`, `reserved_qty`, jumlah enrollment aktif, jumlah accepted, jumlah DP terbayar — masing-masing **sebelum** dan **sesudah** koreksi; jumlah baris yang dibatalkan, di-refund, dan dibiarkan; jumlah `quota` yang dinaikkan (harus 0, atau tercatat dengan persetujuan tertulis).
2. **Bukti penutup yang diminta Blueprint §16**: jalankan test concurrency "kuota 50, 500 request paralel ⇒ **maks 50** accepted, sisanya ditolak tanpa error 5xx" (suite PostgreSQL nyata + k6 — Doc 11 B-11; bukan SQLite). Test ini adalah satu-satunya hal yang menutup Bentuk B secara permanen: kalau test-nya hijau, jalur tulis lain yang jadi tersangka.
3. **Tiket wajib:**
   - audit **`CloseBatch`**: apakah mengunci baris state batch (`FOR UPDATE`) sebelum transisi `open → closing → closed`, dan apakah enrollment yang datang di jendela `closing` dijawab `BatchClosed` alih-alih diantre (**ADR-005**, Doc 11 §8.3);
   - audit **semua jalur tulis** ke `preorder_batch_items.quota/status` dan `.reserved_qty`: setiap jalur yang tidak melewati pagar atomic §8.5 harus dihapus atau dibungkus Command (Doc 11 §2);
   - pastikan pelepasan kuota berjalan bersama pelepasan `stock_reservations` (satu transaksi) — Bentuk D tidak boleh bisa terjadi lagi;
   - ambang di §1 digantikan angka hasil load test (**Q-4**), bukan ditebak.
4. **Tutup celah deteksi**: kalau Bentuk B/D pertama kali diketahui dari keluhan buyer dan bukan dari metrik, tambangkan invarian `reserved_qty <= quota` ke query §3.1 sebagai pemeriksaan terjadwal + alert (Doc 01 §12 dashboard minimum; Blueprint §15 "oversell anomaly").
5. **Perbarui dokumen**: `docs/threat-model.md` (jalur tulis di luar pagar = ancaman integritas data), catatan `preorder_batches` di Doc 09 bila ternyata perlu CHECK `reserved_qty <= quota` di level basis data (gigi pengaman kedua; kalau CHECK itu ada, kegagalan jalur non-pagar menjadi `failed_jobs` constraint, dan itu justru sinyal yang kita inginkan).
6. **Cross-check runbook tetangga**: penyebab stok/kuota yang sama bisa muncul sebagai `oversell.md` (stok fisik), `reservation-expiry-race.md` (reservasi vs pembayaran terlambat), `supplier-failure.md` (kuota lebih besar dari kapasitas supplier — di situ Bentuk A dan B menyatu menjadi masalah komersial).
