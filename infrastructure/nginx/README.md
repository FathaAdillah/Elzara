# infrastructure/nginx/

Konfigurasi edge untuk **staging dan production**. Dev pakai `docker/nginx/dev.conf`;
yang di sini bukan salinan tangan dari berkas itu, melainkan hasil render dari profil
yang sama (Task 0.16). Alasannya satu: edge dev dan edge produksi yang divergasi diam-diam
berarti rate limit, pemisah rute `api/admin/webhook`, dan `location /storage` untuk presign
MinIO diuji di environment yang bukan tempat ia berjalan.

Sampai Task 0.16 dijalankan, direktori ini sengaja kosong — keberadaannya di pohon
Doc 01 §3.1 adalah janji bahwa jalur render-nya ada, bukan klaim bahwa konfigurasinya sudah ada.
Gerbang I (Doc 11 §8.8) menolak kalau suatu saat pohon dan isi repo berbeda arah.
