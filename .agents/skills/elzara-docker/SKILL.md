---
name: elzara-docker
description: Configure, validate, build, or troubleshoot Docker Compose for Elzara on Windows or Dokploy. Use for local stack topology, Dockerfile changes, build failures, or container restart loops in this repository.
---

# Docker Elzara

Baca `docker/README.md`, `docker/compose.yaml`, dan profil yang dipakai. Untuk lokal, gunakan `docker/compose.local.yaml` dengan `docker/.env`. Mailpit hanya dimuat oleh entrypoint ini. Untuk Dokploy, gunakan `docker/compose.yaml`, template produksi dengan SMTP eksternal, dan port internal nginx 8080. Pisahkan konfigurasi dari tindakan build, start, atau deploy. Jalankan tindakan tersebut bila diminta.

## Build development

1. Render dengan `docker compose --env-file docker/.env -f docker/compose.local.yaml config --quiet`. Gunakan output terpilih untuk memeriksa project, ports, dan service. Keluaran config lengkap dapat memuat kredensial.
2. Periksa engine, ruang kosong pada filesystem Docker dan host, dan tiga lockfile. Development memakai HTTP tanpa mount sertifikat. Jalankan `bash scripts/dev.sh` dari Git Bash di Windows. Skrip memakai minimum 15 GiB secara default dan batas 20 menit per image. Atur `ELZARA_DOCKER_DISK_PATH` jika penyimpanan Docker berada pada filesystem berbeda dari repo. Nilai dapat ditetapkan lewat environment shell.
3. Pertahankan dua replika Horizon, satu migration sekali jalan, PostgreSQL internal, dan dua hostname lokal. Worker dan scheduler menunggu migration sukses. Service lokal memiliki restart terbatas. Jika port bentrok, pilih port loopback kosong dan selaraskan origin serta URL penyimpanan. Biarkan proses pemakai port yang ada.
4. Build satu image dalam satu waktu. Jika gagal, rekam service, tahap dan pesan pertama. Ulang hanya setelah penyebabnya ditangani. Jika disk rendah, filesystem read-only, Docker Engine hilang, atau proses build sebelumnya belum berhenti, hentikan pekerjaan dan laporkan kondisi itu. Penghapusan volume, prune global, reset, dan perubahan trust sertifikat memerlukan otorisasi tersendiri. Jika restart Docker Desktop melampaui batas waktu, periksa status dan ruang disk sekali sebelum tindakan pemulihan. Jangan mengulang restart atau menghentikan semua proses WSL sebagai bagian dari build. Hentikan proses secara paksa hanya dengan otorisasi untuk pemulihan dan cakupan proses yang jelas.
5. Setelah build selesai, jalankan startup dengan batas tunggu. Periksa `ps -a`, kode keluar migration, status kedua worker, dan health storefront/admin. Periksa log hanya untuk service yang gagal atau restart. Jelaskan pemeriksaan yang benar-benar dijalankan dan status yang masih terbuka.
