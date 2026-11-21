# docker/

Gunakan Docker Engine dan Docker Compose v2.20 atau lebih baru untuk fitur `include`
di entrypoint lokal. Instalasi dependensi dan build aplikasi berlangsung di dalam
image `php:8.4` dan `node:22` (Doc 01 §11.2).

## Development di Docker Desktop Windows

Gunakan `docker/compose.local.yaml` dengan `docker/.env`. Profil lokal memakai HTTP
dan memuat Mailpit, dua worker, serta service migrasi.

```bash
cp docker/.env.example docker/.env      # isi nilainya; tidak ada rahasia di contoh
bash scripts/gen-lockfiles.sh           # sekali per perubahan dependensi: tiga lockfile di dalam image baseline
bash scripts/dev.sh                     # build berurutan, startup, lalu inisialisasi enam bucket
```

Untuk melihat status dari PowerShell:

```powershell
docker compose --env-file docker/.env -f docker/compose.local.yaml ps -a
```

Default storefront dan admin adalah `http://localhost` dan `http://admin.localhost`.
Origin object storage lokal adalah `http://storage.localhost`; default API mandiri
memakai `https://storage.localhost`. Gunakan hostname `storage.*` yang diarahkan ke
edge (tambahkan routing host di Dokploy dan sertifikat TLS produksi untuk hostname
ini). Jangan tambahkan prefix path: nginx mempertahankan URI dan `Host` untuk SigV4.
MinIO mengizinkan CORS hanya dari `ELZARA_EDGE_URL` dan `ELZARA_ADMIN_ORIGIN`.
Dashboard admin tersedia di `/` dan memverifikasi sesi pada
`GET /api/v1/admin/auth/session` melalui origin admin. Pengunjung tanpa sesi
diarahkan ke `/login`. `ADMIN_SESSION_PATH` menetapkan endpoint verifikasi.
Pratinjau dengan data contoh dinonaktifkan secara default.

Untuk membuat operator pertama di database development dan mengonfirmasi 2FA, jalankan
secara interaktif dengan email operator yang akan dipakai.

```powershell
docker compose --env-file docker/.env -f docker/compose.local.yaml exec api php artisan identity:admin:create operator@example.com "Operator Elzara"
docker compose --env-file docker/.env -f docker/compose.local.yaml exec api php artisan identity:admin:enroll-2fa operator@example.com
```

Tambahkan secret yang ditampilkan ke aplikasi autentikator, masukkan kode untuk
mengonfirmasi pendaftaran, lalu simpan recovery code yang ditampilkan sekali.
Buka `/login` pada origin admin dan masuk dengan email, kata sandi, serta kode 2FA.

Development lokal mendukung login dengan kata sandi untuk satu operator yang
emailnya diisi pada `ELZARA_LOCAL_ADMIN_PASSWORD_ONLY_EMAIL` di `docker/.env`.
Kosongkan nilai itu dan buat ulang container `api` untuk mewajibkan 2FA kembali.
Sesi yang dibuka dengan kata sandi saja akan ditolak pada pemeriksaan berikutnya.

Storefront dan admin memakai `/api/v1` pada origin masing-masing. Nginx tetap terikat pada
loopback. Akses lewat alamat IP `127.0.0.1` dialihkan ke origin storefront yang dikonfigurasi.
Untuk hostname lokal lain, ubah `ELZARA_EDGE_URL` dan `ELZARA_ADMIN_HOST`, daftarkan
hostname di resolver lokal, lalu sesuaikan `ELZARA_ADMIN_ORIGIN` dan `MINIO_PUBLIC_ENDPOINT`.
Inbox Mailpit lokal tersedia di `http://localhost:8025`.
Jika port host sudah dipakai, atur `ELZARA_LOCAL_HTTP_PORT` dan `ELZARA_LOCAL_MAIL_PORT`.
Sertakan port HTTP yang dipilih di `ELZARA_EDGE_URL`,
`ELZARA_ADMIN_ORIGIN`, dan `MINIO_PUBLIC_ENDPOINT`.

Profil lokal menjalankan dua replika `worker` dan satu `migration` yang berhenti
dengan kode 0 setelah migrasi selesai. Worker dan scheduler menunggu keberhasilan
migrasi. PostgreSQL tampil sebagai service `postgres`. Image MinIO development
dibangun dari rilis sumber yang ditetapkan di `docker/minio/Dockerfile`.

`scripts/dev.sh` memvalidasi konfigurasi, lockfile, Docker Engine,
dan ruang kosong sebelum build. Default preflight membutuhkan 15 GiB dan memberi
batas 20 menit per image. Atur `ELZARA_DOCKER_DISK_PATH` di environment shell jika
penyimpanan Docker berada pada filesystem lain. `ELZARA_DEV_MIN_FREE_GIB` dan
`ELZARA_DEV_BUILD_TIMEOUT` dapat disesuaikan. Skrip membangun satu image per tahap
dan berhenti pada kegagalan pertama. Runtime lokal memakai `on-failure:3`; service
`migration` tidak di-restart otomatis.

`gen-lockfiles.sh` wajib sebelum build pertama: `composer install` dan `npm ci` di Dockerfile menolak bekerja
tanpa lockfile, dan lock yang dihasilkan toolchain host (Node 26 / PHP 8.3 di mesin ini) bukan baseline.

`compose.yaml` adalah file production untuk Dokploy dan menjadi basis entrypoint
development. Profil staging dan production standalone untuk edge dengan sertifikat
sendiri tetap tersedia di `profiles/`.

### API autentikasi admin

Laravel melayani rute autentikasi melalui Nginx pada origin admin. Port `9000`
di container `api` memakai FastCGI. Daftar rute yang terdaftar di image API lokal
dapat diperiksa dengan perintah berikut.

```powershell
docker compose --env-file docker/.env -f docker/compose.local.yaml exec api php artisan route:list --path=api/v1/admin/auth
```

Untuk memanggil API secara langsung, ambil CSRF dari `/sanctum/csrf-cookie?scope=admin&purpose=login`
sebelum `POST /api/v1/admin/auth/login` dan `POST /api/v1/admin/auth/2fa/verify`.
`GET /api/v1/admin/auth/session` memeriksa sesi hasil verifikasi. Ambil CSRF dari
`/sanctum/csrf-cookie?scope=admin` sebelum `POST /api/v1/admin/auth/logout`.

## Production di Dokploy

1. Pilih **Docker Compose** dan arahkan Compose Path ke `docker/compose.yaml`.
2. Isi **Environment** dari `docker/.env.production.example`. Atur `ELZARA_EDGE_URL`
   ke URL HTTPS storefront, `ELZARA_ADMIN_HOST` ke hostname dashboard tanpa skema atau
   path, `ELZARA_ADMIN_ORIGIN` ke URL HTTPS dashboard, serta `MINIO_PUBLIC_ENDPOINT`
   ke origin khusus `https://storage.<domain>` tanpa prefix path. Isi `SMTP_HOST`, `SMTP_PORT`, dan kredensial
   provider SMTP produksi. Compose Dokploy tidak memuat Mailpit.
3. Arahkan DNS ketiga hostname (storefront, admin, storage) ke Dokploy. Tambahkan ketiganya di **Domains** dengan
   service `nginx`, port container `8080`, path `/`, dan HTTPS aktif. Traefik
   menangani sertifikat produksi. Basis Compose hanya membuka `8080` di jaringan container.
4. Setelah gerbang rilis siap, gunakan versi Git yang sama untuk ketiga image.
   Jalankan migrasi dan inisialisasi bucket di lingkungan yang dituju sesuai runbook.
   Image MinIO hasil build memuat `mc`. Untuk skrip bootstrap, gunakan
   `MC_IMAGE=elzara/minio:<tag-rilis>` dan `ELZARA_MINIO_SERVER_CORS=1`.
   Verifikasi `/health` pada kedua domain, termasuk aset dashboard dan rute API.

Gunakan satu domain induk untuk storefront dan admin bila memungkinkan. Review
ADR-007 dan sign-off threat model tetap diperlukan sebelum mengaktifkan auth pada
topologi dua hostname, termasuk cookie host-only serta CSRF pada masing-masing origin.

## Elasticsearch

Service `elasticsearch` memakai image resmi 8.19.25 dan lisensi Basic gratis.
PostgreSQL menyimpan data utama. Indeks katalog disimpan di volume `elasticsearchdata`
dan bisa dibangun ulang dari PostgreSQL setelah fitur indexing tersedia.

Untuk development, isi `ELASTICSEARCH_PASSWORD` di `docker/.env` lalu jalankan:

```bash
docker compose --env-file docker/.env -f docker/compose.local.yaml --profile search up -d elasticsearch
docker compose --env-file docker/.env -f docker/compose.local.yaml --profile search ps elasticsearch
```

Untuk Dokploy, set `COMPOSE_PROFILES=search` dan isi password bootstrap di Environment.
Akses HTTP berada di jaringan Docker internal `search`. Service ini tidak membuka port
host atau domain publik. API dan worker menggunakan `http://elasticsearch:9200`.
Isi `ELASTICSEARCH_API_KEY` dengan key berizin terbatas ke indeks aplikasi ketika
fitur pencarian mulai digunakan. Password bootstrap tetap khusus untuk administrasi
Elasticsearch. Batas RAM service dapat diubah lewat `ELASTICSEARCH_MEMORY_LIMIT`
dan default-nya 1 GiB. Profil ini dapat diaktifkan terpisah dari startup admin.

## Batas yang ditegakkan di sini

- postgres/redis/minio/elasticsearch tidak mempublikasikan port ke host: `ssh -N -L 5432:elzara-postgres-1:5432 <host>`.
- tujuh antrian Horizon: `payments, orders, inventory, notifications, imports, reports, media` (Doc 11 §8.1).
- enam bucket objek: `product-media`, `request-attachments`, `invoice-documents`, `shipment-documents`,
  `exports` (TTL 7 hari), `audit-archives` (hanya tulis) — `docker/minio/init-buckets.sh`, idempoten.
- backup WAL/dump ke tujuan off-node terenkripsi, bukan bucket di node ini (Doc 11 §8.7).
