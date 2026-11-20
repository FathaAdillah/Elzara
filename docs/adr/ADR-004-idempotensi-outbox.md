# ADR-004 — Idempotensi mutasi, unique constraint, dan transactional outbox

Tanggal: 2026-11-17 · Status: **Accepted** · Menyelesaikan bentrok **B-7** (Doc 11 §1) · Rujukan induk: Blueprint §6 (Reliability), §8.2, §8.3, §9 (ADR-003)

## Konteks

Blueprint §18 butir 3 menuntut keputusan tertulis sebelum coding. Bentrok yang ia buka (B-7) nyata: skema lama kita (Doc 09 §9/§17) hanya punya satu pagar anti-duplikat, yaitu keunikan `(provider, provider_event_id)` pada tabel webhook. Tidak ada:

- ruang lingkup idempotensi untuk mutasi yang **diawali klien** (buat order, buat tagihan, refund) — sehingga `POST /orders` yang di-retry karena koneksi putus menghasilkan dua order;
- deduplikasi untuk **konsumen event** — sehingga konsumen yang di-retry setelah crash menjalankan efek samping dua kali;
- arsip payload webhook yang terverifikasi terpisah dari hasil pemrosesannya.

Dua keadaan memperparah: (a) topologi tiga aplikasi (B-1) dengan TypeScript client hasil generate (B-2) membuat retry terjadi di tiga tempat berbeda (browser web, browser admin, worker) dan tidak bisa dicegah dari UI; (b) reservasi stok yang kita jamin di **ADR-005** hanya benar terhadap satu permintaan — dua permintaan yang sama-sama *sah* tetap menghasilkan dua reservasi dan dua tagihan kalau tidak ada kunci idempotensi. Ini juga menutup acceptance test Blueprint §16 baris "Duplicate same idempotency key (100 parallel requests)".

## Keputusan

1. **Header `Idempotency-Key` wajib** untuk seluruh mutasi order/charge/refund: pembuatan order (`POST /orders`, termasuk order yang lahir dari `POST /quotations/{id}/accept` — Blueprint §5.3 butir 5), `POST /payment-intents`, setiap mutasi refund (dari API maupun dari kerja admin), `POST /admin/preorder-batches/{id}/close`, dan setiap mutasi uang manual dari admin. Nama rute mengikuti daftar minimum Blueprint §7; rute refund dan pengiriman admin belum ada di daftar itu dan akan masuk lewat perubahan kontrak (gerbang kontrak ADR-010). Permintaan mutasi tanpa header ditolak dengan RFC 7807 (Blueprint §7 konvensi).
2. **Ruang lingkup `(actor, operation, key)`** disimpan di tabel `idempotency_keys` dengan hash permintaan, status, dan hasil tersimpan. Batas keunikan persis constraint Blueprint §6: `UNIQUE(customer_id, idempotency_key, operation)` — kunci yang sama di dua operasi berbeda **bukan** tabrakan.
3. **Perbandingan hash payload.** Kunci sama + payload sama → replay hasil yang tersimpan (kode status dan body yang sama, termasuk order number yang sama). Kunci sama + payload berbeda → `409`, tanpa efek samping. Tabrakan paralel untuk kunci sama diselesaikan oleh constraint unik + row lock, bukan oleh "yang terakhir menang".
4. **Tidak ada efek samping sebelum commit DB** (Blueprint §9): draf/panggilan gateway, notifikasi, dan publish event semuanya terjadi setelah transaksi domain commit. Yang boleh di dalam transaksi hanyalah baris `idempotency_keys` dan `outbox_events` milik transaksi yang sama.
5. **Outbox transaksional.** `outbox_events` ditulis dalam transaksi agregat yang sama (aturan B4 Doc 01 §3, sudah ada), dispatcher membacanya **setelah** commit. Event yang belum commit tidak pernah terlihat oleh dunia luar.
6. **Konsumen at-least-once wajib dedup lewat `inbox_events`** — dicatat per `(konsumen, event_id)` sebelum efek samping dijalankan; retry setelah crash adalah no-op, bukan duplikat. Handler tetap harus idempoten (Blueprint §9 reliability).
7. **Callback provider** diverifikasi penuh sebelum apa pun berubah: tanda tangan, merchant/account id, nominal, mata uang, referensi order/invoice, dan **keabsahan transisi status**; payload mentah diarsipkan di `webhook_receipts` dengan `UNIQUE(payment_provider, provider_event_id)` dan `UNIQUE(provider, provider_payment_id)`. ACK diberikan cepat segera setelah persist, pekerjaan lanjutan (ledger, invoice berikutnya, notifikasi, pick list) async lewat queue `payments`.
8. **State machine sebagai pagar terakhir**: transisi mundur ditolak (`paid` tidak pernah kembali `pending`) — Blueprint §8.3 butir terakhir.

## Alternatif yang ditolak

| | Ditolak | Alasan kalah |
|---|---|---|
| A | Hanya unique constraint pada kolom bisnis (desain lama) | Tidak mengembalikan hasil yang sama untuk retry; tidak menutupi operasi non-insert (refund, close batch); tidak menduplikasi perlindungan untuk konsumen event |
| B | Nomor order dibuat klien sebagai kunci alami | Memindahkan tanggung jawab ke setiap konsumen API (web, admin, integrasi pihak ketiga) dan tetap tidak melindungi charge/refund; kunci klien bocor ke log |
| C | Transaksi DB + retry ORM tanpa menyimpan hasil | Gagal untuk kasus paling penting: commit sudah sukses tapi respons hilang di jaringan — retry kedua membuat baris kedua |
| D | Publish event langsung setelah commit di proses yang sama | Crash di antara commit dan publish = event hilang permanen; rekonsiliasi, notifikasi, dan proyeksi laporan ikut bolong. Inilah alasan §8.2 menempatkan outbox di atas queue biasa |
| E | Broker durable (Kafka/RabbitMQ) sejak MVP | Blueprint §9 eksplisit: jangan menambah broker tanpa kebutuhan; Redis + Horizon + outbox cukup untuk beban kami |

## Konsekuensi

**Yang jadi wajib:** tiga tabel keandalan baru (`idempotency_keys`, `inbox_events`, `webhook_receipts`) masuk Doc 09 dan satu band migrasi sendiri di Doc 10; `webhook_events` lama menjadi `webhook_receipts` (payload mentah + verifikasi), bukan tabel kedua untuk fakta yang sama. Gerbang CI harus menolak route mutasi uang/order yang tidak mendeklarasikan kunci idempotensi (ADR-010).

**Yang jadi mahal:** setiap mutasi membayar satu putaran tulis tambahan ke `idempotency_keys`; penyimpanan hasil membuat respons harus bisa diserialkan ulang secara stabil — perubahan bentuk DTO setelah hasil tersimpan adalah perubahan kontrak. Kedua aplikasi frontend harus menyimpan kunci per *maksud pengguna* (bukan per permintaan HTTP): retry memakai kunci yang sama, niat baru memakai kunci baru.

**Yang jadi mustahil:** charge ganda tanpa jejak, order ganda akibat retry jaringan, dan "notifikasi terkirim dua kali karena worker crash" sebagai jawaban insiden.

**Peringatan yang harus diperiksa manusia:** kolom `idempotency_key` pada `payment_intents` (Doc 09 §9) adalah kunci di **sisi provider**, bukan ruang lingkup klien. Keduanya bukan pengganti satu sama lain dan tidak boleh dipakai bergantian di kode.

**Kalau diabaikan:** retry provider menghasilkan dua capture dan dua ledger entri untuk satu invoice; refund parsial bisa dieksekusi dua kali; ADR-005 kehilangan makna karena reservasi yang benar dilindungi oleh permintaan yang salah.

## Pertanyaan yang masih terbuka

Berapa TTL retensi baris `idempotency_keys` (default kami: mengikuti umur order + 90 hari, lalu dibersihkan dengan alasan tercatat di `audit_logs`), dan apakah kunci yang sama boleh dipakai ulang antar-environment (jawaban kami: tidak — `local`/`staging`/`production` punya tabel dan gateway sendiri, Blueprint §14.3).
