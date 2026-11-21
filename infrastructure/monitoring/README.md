# infrastructure/monitoring/

Alert Prometheus dan dashboard Grafana untuk staging/production. Daftar metrik wajib dimiliki
[../../docs/01-architecture.md](../../docs/01-architecture.md) §12; direktori ini menerjemahkannya.

| Berkas | Isi |
|---|---|
| `prometheus/prometheus.yml` | scrape `nginx:9145/metrics` (listener internal `docker/nginx/metrics.conf` → `/metrics` Laravel, dijaga `InternalAccess`) |
| `prometheus/alerts.yml` | 5xx, p95, queue lag, DLQ, outbox macet, oversell/paid-stock/RBAC bypass, error MinIO, lock/deadlock, saturasi koneksi, arsip WAL |
| `grafana/provisioning/` | datasource Prometheus + provider dashboard |
| `grafana/dashboards/elzara-overview.json` | dasbor ringkasan operasional |

Dijalankan dengan profil compose `monitoring` (Prometheus + Grafana di jaringan `backend`, tidak di-publish; akses lewat SSH
tunnel). Validasi: `docker run --rm --entrypoint promtool -v $PWD/infrastructure/monitoring/prometheus:/etc/prometheus:ro prom/prometheus:v3.5.0 check config /etc/prometheus/prometheus.yml`.

Ambang alert adalah angka awal; sesuaikan setelah baseline staging. Metrik yang sumbernya belum ada di kode
(lag Elasticsearch, admisi Waiting Room, outcome payment/reservasi) sudah terdaftar di
`apps/api/app/Platform/Observability/Metrics.php` dan dinaikkan oleh modul pemiliknya lewat `Metrics::increment()` / `Metrics::observe()`.
Log JSON (stderr) dikumpulkan dari driver log Docker; agregator (Loki) belum dipilih.
