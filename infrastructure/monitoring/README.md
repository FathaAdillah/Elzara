# infrastructure/monitoring/

Aturan alert Prometheus/Loki dan dashboard Grafana untuk staging/production.

Daftar metrik yang **wajib ada sejak Sprint 0** tidak ditulis ulang di sini — pemiliknya
[../../docs/01-architecture.md](../../docs/01-architecture.md) §12 (RPS; p50/p95/p99 per rute,
khususnya `POST /orders` dan `POST /checkout/sessions`; error rate per rute dan per aplikasi;
queue lag per pool Horizon + jumlah job di DLQ; **oversell anomaly** yang harus nol + jumlah
order yang masuk `paid_stock_exception`; lock waits/deadlock per 5 menit/retry/connection-pool
saturation; Redis memory; MinIO error rate; lag pengindeksan Elasticsearch). Direktori ini hanya
menerjemahkan daftar itu menjadi aturan alert.

Belum ada isi karena dashboard yang dipasang setelah insiden tidak mengubah apa pun:
ambang alert bergantung pada angka baseline yang baru bisa diukur setelah stack berjalan
di staging. Yang mengikat sekarang adalah bahwa metriknya ada di kode, bukan di folder ini.
