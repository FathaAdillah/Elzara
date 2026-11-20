# ADR-008 — Object storage MinIO: bucket publik/privat dan pemakaian CDN

Tanggal: 2026-11-17 · Status: **Accepted** — dengan satu butir terbuka (CDN/edge mana yang benar-benar dimiliki klien; terkait Doc 11 §6 Q-6) · Menyelesaikan bentrok **B-9** (Doc 11 §3: Blueprint §18 butir 7) · Rujukan induk: Blueprint §11, §16, §19 (ADR-003)

## Konteks

Dokumen lama hanya menyebut dua bucket (`media-public` / `media-private`) di Doc 01 §10 dan sekilas "upload lampiran" di Doc 02 §3. Blueprint §11 menaikkan tuntutannya menjadi enam bucket dengan klasifikasi akses, alur presigned-upload penuh, validasi MIME/magic-byte, pemindaian, URL bertanda tangan untuk unduhan privat, dan CORS per origin — semuanya sebagai persyaratan, bukan saran.

Ada tiga tekanan konkret yang membuat ini harus diputuskan sekarang:

1. **Lampiran request order dan bukti bayar manual mengandung PII** (foto barang, tangkapan layar transfer, alamat), sementara foto produk harus bisa di-cache publik. Menyimpan keduanya di bucket yang sama berarti memilih antara membocorkan atau memblokir katalog.
2. Bukti transfer untuk penanda-lunas-manual (aturan **A4 ADR-002**) wajib tahan-audit dan tidak boleh bisa diakses publik — itu kebutuhan keuangan, bukan kebutuhan UX.
3. Export laporan dan arsip audit punya masa simpan berbeda dari media katalog; tanpa pemisahan bucket, lifecycle policy tidak bisa diterapkan tanpa ikut menghapus foto produk.

Kita juga mewarisi satu jebakan operasional yang disebut Blueprint §11 secara eksplisit; melihat repo ini dijalankan dengan Docker Compose di VPS tunggal (Doc 01 §10, dan peringatan SPOF di B-15), jebakan itu bukan teori.

## Keputusan

**K-1. Enam bucket, namanya persis Blueprint §11:** `product-media` (publik, hanya lewat CDN terkontrol), `request-attachments` (privat), `invoice-documents` (privat), `shipment-documents` (privat), `exports` (privat, ber-TTL), `audit-archives` (terbatas). Bucket-bucket ini menggantikan dua nama lama; tidak ada bucket ketujuh tanpa ADR baru.

**K-2. Alur unggah adalah presigned, bukan perantara aplikasi:** peramban → validasi autentikasi + tipe/ukuran + batas kuota di `api` → presigned PUT/POST berumur pendek → unggah langsung ke MinIO → **finalize** menuliskan metadata dan kepemilikan objek → pekerjaan async melakukan validasi MIME + **magic-byte** (ekstensi tidak pernah dipercaya), pemindaian antivirus bila penyedia tersedia, pemrosesan gambar, dan moderasi. Konsekuensi arsitektur yang harus diterima: objek boleh eksis beberapa menit tanpa metadata sah (objek yatim), jadi wajib ada pembersih periodik; dan validasi yang berjalan sinkron **tidak bisa** dijadikan pagar keamanan terakhir karena pemindaian terjadi setelahnya.

**K-3. Kunci objek diturunkan server, bukan klien:** UUID/name opaqued, prefix per modul, tanpa nama asli berkas pengguna di path publik; `media` (pemilik modul Content, aturan S-3) tetap satu-satunya tabel metadata — lampiran request tidak dapat tabel kedua untuk fakta yang sama (Doc 11 §4.2 mencatat `request_attachments` sudah diwakili `media`).

**K-4. Akses privat hanya lewat URL bertanda tangan berumur pendek** (atau proksi terautentikasi untuk unduhan yang harus bisa dicabut segera). Tidak ada presigned panjang, tidak ada "link aman karena tebak sulit".

**K-5. CDN dipakai untuk media publik saja.** Foto produk, banner, dan aset katalog lewat edge; **tidak pernah** untuk cart, sesi, checkout, halaman tagihan, data pelanggan, maupun respons privat apa pun — sejalan Blueprint §12 dan daftar Definition of Done Blueprint §19 ("CDN/cache tidak membocorkan data pengguna").

**K-6. Operasional yang ikut mengikat:** akses konsol MinIO tidak pernah publik; kunci akses terpisah per servis; kebijakan bucket least-privilege; CORS bucket dibatasi ke origin `web` dan `admin` yang sah; versioning untuk dokumen kritis (invoice, audit); lifecycle expiration wajib untuk `exports`; SSE dipakai bila versi MinIO yang kita pilih mendukungnya; kapasitas dipantau sebagai metrik, bukan kejutan.

**K-7. Yang menjadi dasar keputusan (jebakan operasional):** **presigned URL harus menunjuk endpoint yang benar-benar bisa dijangkau peramban.** Hostname internal Docker (`http://minio:9000`) tidak akan selesai di peramban pengguna, berapa pun lamanya masa berlaku URL. Implementasinya: satu origin publik khusus object storage (subdomain ber-TLS di belakang Cloudflare/nginx) yang dipakai browser dan server untuk operasi yang sama, dengan bucket `product-media` dilindungi referer/CDN dan bucket privat hanya menjawab URL bertanda tangan.

## Alternatif yang ditolak

| | Ditolak | Alasan kalah |
|---|---|---|
| A | Unggah lewat proxy aplikasi (berkas masuk ke Laravel lalu diteruskan ke MinIO) | Memakan memori/waktu worker untuk berkas besar, menembus timeout, dan membuat upload menjadi pekerjaan sinkron; Blueprint §11 memilih presigned secara eksplisit |
| B | Menyimpan berkas sebagai `bytea` di PostgreSQL | Metadata dan blob jadi satu siklus hidup yang sama: backup/PITR database membengkak, ekspor laporan besar tidak bisa diberi TTL, dan caching tepi tidak mungkin |
| C | Bucket tunggal publik + path "rahasia" untuk dokumen privat | Tidak ada keamanan lewat obskuritas; satu kebocoran daftar URL = bocor PII dan bukti bayar; Doc 19 menjadikannya kegagalan definisi-selesai |
| D | MinIO diekspos langsung ke publik tanpa CDN untuk media katalog | Setiap tampilan halaman produk menekan origin (VPS tunggal, B-15), tanpa cache penghapus, dan TLS/limit harus kita kelola sendiri |
| E | Layanan object storage terkelola komersial sejak MVP | Alasannya masuk akal (hilang satu komponen yang harus di-ops) tapi Blueprint §11/§14.1 menetapkan MinIO, dan biaya + lock-in tidak sepadan untuk target awal; **catatan jujur**: keputusan ini bisa dibalik kalau beban operasi MinIO terbukti terlalu mahal bagi tim 1–2 dev — itu ADR baru, bukan selisih konfigurasi |
| F | Satu bucket privat dengan proksi terautentikasi untuk **semua** media (termasuk katalog) | Aman tapi memaksa setiap gambar melayani dari origin; menolak CDN sama dengan menolak satu-satunya lapisan yang melindungi VPS tunggal dari trafik media |

## Konsekuensi

**Biaya yang jadi nyata:** satu komponen infrastruktur tambahan (MinIO + volume persisten) dengan backup dan uji restore sendiri; pekerjaan async untuk validasi/pemindaian/proses gambar di pool `media` (Blueprint §9); pembersih unggahan yatim; dan dua origin yang harus dikelola di edge.

**Jadi tidak mungkin:** menjanjikan "upload langsung tampil di CDN" sebagai acceptance criterion — antara presigned-PUT selesai dan objek siap lewat edge selalu ada antrean validasi. Janji UX yang benar adalah "sedang diproses", bukan "gagal".

**Jadi tidak mungkin juga:** memindahkan dokumen privat ke bucket publik "sementara untuk testing", karena tidak ada mekanisme di CI yang bisa membuktikan bahwa tidak ada objek privat tertinggal setelahnya.

**Butir terbuka (harus dijawab klien, terkait Q-6):** CDN/edge apa yang benar-benar sudah dimiliki. Blueprint mengandaikan Cloudflare untuk media + WAF + Waiting Room, tapi punya akun Cloudflare ≠ punya fitur CDN/proxy yang aktif untuk zone ini, dan kita tidak boleh memasang `product-media` di belakang CDN yang belum dibayar. Default sementara kami sampai ada jawaban: media publik dilayani lewat origin dengan `Cache-Control` + caching di nginx, lalu dipindah ke CDN begitu zone-nya dikonfirmasi; pemindahan itu tidak mengubah kontrak apa pun karena URL objek dipegang satu kolom di `media`.

**Ruang lingkup tes yang ikut terbuka (Blueprint §16 baris "MinIO unavailable"):** unggah harus gagal dengan aman dan checkout harus tetap jalan ketika media tidak bergantung padanya — artinya modul Orders **tidak boleh** memanggil object storage di jalur transaksi.
