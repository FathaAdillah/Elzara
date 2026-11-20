# ADR-009 — Cakupan rute, ambang batas, dan kebijakan bypass untuk Virtual Waiting Room

Tanggal: 2026-11-17 · Status: **Proposed — diblokir** dua jawaban: hasil load test (Doc 11 §6 Q-4) dan paket Cloudflare yang dimiliki klien (Doc 11 §6 Q-6) · Menyelesaikan bentrok **B-8** (Doc 11 §3: Blueprint §18 butir 8, "baru → ADR-009; butuh angka load test") · Rujukan induk: Blueprint §3, §10.1, §10.2, §10.3, §15, §16 (ADR-003)

## Konteks

Sebelum rekonsiliasi, tidak satu pun dokumen kita punya antrean pengunjung: Doc 01 §2 hanya berisi nginx → app → Postgres, dan perlindungan lonjakan trafik diasumsikan cukup dengan rate limit (Doc 11 B-8 mencatat ini sebagai cakupan yang **ditambahkan** Blueprint, bukan diperdebatkan). Blueprint §10 menambahkan satu lapisan baru (admission control di edge) sekaligus dua peringatan keras yang harus dibaca bersamaan:

- §3: kalau Waiting Room hanya dipasang di homepage, permintaan API langsung tetap bisa membanjiri origin — yaitu persis jalur yang dipakai checkout.
- §10.2 butir 6: tidak semua fitur tersedia di semua paket, sehingga konfigurasi path, cookie sesi, dan bypass **wajib** diverifikasi terhadap dokumentasi dan paket aktif sebelum implementasi.

Dua hal inilah yang membuat ADR ini tidak bisa ditutup sendiri oleh tim dev. **Ambang batas kapasitas adalah hasil pengukuran, bukan opini** (Blueprint §1: "Tentukan RPS/kapasitas melalui load test, bukan asumsi"), dan kita belum menjalankan k6 di staging (Doc 11 Q-4; peranti kerasanya dijanjikan B-11). **Fiturnya sendiri adalah barang berbayar** yang kontraknya di tangan klien (Q-6) — bila paket tidak menyertakan Waiting Room, seluruh isi ADR ini berubah menjadi pekerjaan aplikasi-side yang jauh lebih mahal, atau cakupan Phase 4 dipotong (opsi J-b, Doc 11 §5).

## Keputusan — bagian yang bisa dikunci sekarang

**D-1. Cakupan admission = rute transaksi, bukan halaman dekorasi.** Kebijakan yang kita ajukan menetapkan Waiting Room pada: (a) seluruh rute checkout dan order-creation di storefront, (b) rute API `/api/v1/orders`, `/api/v1/checkout/sessions`, `/api/v1/payment-intents`, (c) halaman kampanye/batch populer dan halaman acara/limit yang ditentukan, dan (d) rute login/OTP bila kebijakan anti-bot edge belum cukup. Ini adalah jawaban langsung atas peringatan §3: memasang antrean hanya pada homepage adalah konfigurasi yang **salah**, bukan konfigurasi yang aman-aman saja. Konsekuensi yang harus diterima: keputusan **ADR-007** ikut menentukan bentuk "rute API" itu — kalau BFF yang terpilih, antrean menahan permintaan dari peramban ke origin aplikasi dan kita harus meneruskan/menolak tiket admission secara konsisten di lapis itu; kalau akses langsung yang terpilih, antrean harus mencakup hostname API itu sendiri.

**D-2. Bypass eksplisit, terdokumentasi, dan diuji — bukan default terbuka.** Yang dikecualikan dari antrean, dengan syaratnya masing-masing:

| Yang di-bypass | Syarat |
|---|---|
| Webhook payment (`POST /api/v1/webhooks/payments/{provider}`) | Rute terpisah, verifikasi tanda tangan, WAF policy khusus, rate limit sendiri; **tidak pernah** diantrikan sebagai pengunjung (Blueprint §10.2 butir 7). Alasannya bukan kenyamanan: antrean berarti provider timeout → retry badai → pembayaran hilang |
| Health probe / readiness | Tanpa autentikasi, tanpa cookie, tanpa log; harus tetap menjawab saat origin sibuk supaya orkestrasi tidak membunuh container yang justru sedang hidup |
| Kerja server-to-server (rekonsiliasi gateway, sinkronisasi kurir, import) | Kredensial servis + pembatasan origin/IP, bukan path publik |
| Sesi yang **sudah** admitted, untuk menyelesaikan checkout | Prioritas pelanggan yang sudah masuk antrean (Blueprint §10.2 butir 4); menolak mereka di langkah bayar adalah kerugian tanpa manfaat |
| Rute admin | Bukan lewat antrean; lewat kebijakan akses internal/Zero Trust + MFA (Blueprint §12) — admin tidak boleh "antri" di belakang trafik publik |

**D-3. Admission tidak pernah menjadi mekanisme keadilan stok.** Blueprint §10.2 dan §10.3 melarangnya, dan larangan itu kita adopsi sebagai aturan teknis: antrean mengendalikan **berapa banyak** permintaan masuk, bukan **siapa dapat barang**. Keunggulan siapa-dulu-dapat tetap ditentukan reservasi atomik (ADR-005), dan untuk penjualan terbatas kita harus memilih satu kebijakan tegas (reservasi pertama yang sukses / undian / FIFO atas tiket yang dikeluarkan server). Timestamp peramban, urutan Redis, atau urutan kedatangan HTTP **tidak diakui sebagai bukti keadilan**.

**D-4. Pemisahan cache wajib ikut kebijakan ini.** Halaman publik yang boleh di-cache dipisahkan dari halaman pribadi; cart, sesi, checkout, pembayaran, dan data pelanggan tidak boleh di-cache di tepi (Blueprint §10.2 butir 3) — ini batas yang sama yang sudah diterima di ADR-008, dan di sini ia menentukan mana yang **boleh berada di belakang** antrean.

**D-5. Kapasitas dihitung dari bawah ke atas, bukan diasumsikan.** Ambang batas diturunkan dari hasil k6 di staging pada jalur checkout nyata (bukan homepage), dibagi jumlah replika ter-deploy, dikurangi safety margin; angka itu disimpan di infrastruktur sebagai kode (bukan sebagai angka di dashboard) supaya bisa direproduksi. Metrik Cloudflare `admissions` dan `waiting time` ikut masuk dashboard observability (Blueprint §15).

## Alternatif yang ditolak

| | Ditolak | Alasan kalah |
|---|---|---|
| A | Rate limiting nginx/Cloudflare saja, tanpa Waiting Room | Menolak permintaan dengan 429 tanpa pengalaman antrean, tanpa posisi, dan tanpa prioritas bagi yang sudah admitted; untuk kampanye drop ini berarti kehilangan konversi, bukan melindungi origin secara anggun |
| B | Antrean dibuat di level aplikasi (Redis list + halaman "tunggu") | Kita mengoperasikan sendiri kegagalan kapasitas di dalam proses yang sama dengan yang sedang kebanjiran; tidak ada beban yang terlepas di edge; Blueprint §10.1 menempatkan antrean aplikasi sebagai **pekerjaan latar**, bukan antrean pengunjung |
| C | Menambah replika secara otomatis dan menganggap autoscaling menyelesaikan lonjakan | Blueprint §14.2 eksplisit: menaikkan API tidak menyelesaikan bottleneck database, dan reservasi stok adalah contention pada satu baris (ADR-005) — autoscaling justru memperbesar tekanan pada titik yang sama |
| D | Waiting Room di homepage saja (konfigurasi minimum) | Melanggar §3 dengan terang: API checkout tetap terbuka; kita mendapat rasa aman tanpa perlindungan |
| E | Memakai Waiting Room sebagai penjaga first-come-first-served untuk stok | Dilarang Blueprint §10.2/§10.3 dan akan salah secara hukum-operasional (pelanggan menganggap posisi antrean = janji barang) |

## Konsekuensi

**Status ADR ini akan tetap Proposed sampai dua hal terjadi:** (1) k6 berjalan di staging dan menghasilkan angka kapasitas teruji (Q-4), (2) klien memastikan paket Cloudflare-nya punya Waiting Room serta fitur turunan yang kita andalkan (Q-6). Menyatakan "keputusan diterima" sekarang berarti menulis angka yang belum diukur dan mengandalkan fitur yang belum dibayar.

**Biaya baru yang nyata:** satu lapisan konfigurasi edge yang bisa salah-set (runbook `cloudflare-waiting-room.md` dijanjikan B-8), halaman antrean ber-merek + status page + analitik (Blueprint §10.2 butir 5), dua tes penerimaan baru yang masuk Definition of Done (Blueprint §16: "Waiting Room under load", "Cloudflare bypass/direct API traffic"), dan kebutuhan menguji bypass webhook secara sadar karena ia berada di jalur uang.

**Jadi tidak mungkin:** menjanjikan "sistem kuat menahan trafik berapapun". Waiting Room menurunkan laju masuk, tidak menambah kapasitas; bila stok, DB, atau pool koneksi yang habis, hasilnya hanya jadi antrean yang lebih panjang.

**Kalau diabaikan tanpa keputusan:** konfigurasi paling mungkin yang terjadi adalah antrean di homepage saja (Ditolak di atas sebagai Opsi D), dan lonjakan berikutnya menghantam `/api/v1/orders` langsung — kerugian terbesar justru terjadi pada saat trafik tinggi, yaitu ketika sistem sedang diuji.
