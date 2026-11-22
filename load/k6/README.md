# Uji beban k6 (P0.24)

Skenario k6 untuk baseline kapasitas katalog dan checkout (Sprint plan 1.7, ADR-009). Skrip yang
sama menembak stack Docker lokal atau staging; target dan beban hanya datang dari env.

> P0.24 di `.codex/plans/tasklist.md` **tetap terbuka**: baseline staging (RPS katalog dan checkout,
> laporan kapasitas pertama ke klien) belum diukur karena host dan registry staging Dokploy belum
> ada. Run lokal membuktikan skrip berjalan, bukan kapasitas.

## Skenario

| Berkas | Alur | Ambang lulus |
|---|---|---|
| `catalog.js` | `GET /products` → halaman cursor berikutnya → `GET /products/{slug}`, tanpa login | checks > 99%, `http_req_failed` < 1%, p95 < 800 ms, 0 kesalahan 5xx |
| `checkout.js` | login → keranjang → checkout session → opsi kirim → pilih kurir → `POST /orders` → Fake payment; satu akun per VU | checks > 99%, `http_req_failed` < 1%, p95 checkout session < 1,5 s, p95 order < 2 s, 0 kesalahan 5xx |
| `contention.js` | `CONTENTION_BUYERS` pembeli menyiapkan checkout varian berstok `CONTENTION_STOCK`, lalu memesan bersamaan | order sukses ≤ stok dan > 0, sisanya 409/422, 0 kesalahan 5xx |

`PROFILE=smoke` (default) adalah run pendek untuk membuktikan skrip; `PROFILE=baseline` adalah bentuk
beban untuk staging (katalog: arrival rate naik ke `RATE` req/s; checkout: VU naik ke `VUS`). Override
per run: `VUS`, `MAX_VUS`, `DURATION`, `RATE`, `ITERATIONS`, `THINK_SECONDS`, `PLACE_ORDER=false`.
`elzara_rate_limited` menghitung 429 — angka baseline hanya sah bila nilainya 0.

## Data uji

`LoadTestSeeder` (menolak `APP_ENV=production`) memanggil seeder demo lalu membuat akun
`load.customer.0001…NNNN@elzara.test` (password `LOADTEST_PASSWORD`, jumlah `LOADTEST_CUSTOMERS`,
default 50), produk `load-checkout-plush` berstok 1.000.000, dan `load-contention-plush` berstok
`LOADTEST_CONTENTION_STOCK` (default 10). Setiap seed mengarsipkan produk kontensi lama dan membuat
yang baru, jadi run kontensi berikutnya mulai dari stok penuh. Password tidak pernah di-commit.

## Lokal

```bash
# docker/.env: CHECKOUT_FAKE_PAYMENT_ENABLED=true; naikkan ELZARA_RATE_PUBLIC/ELZARA_RATE_WEB
# supaya rate limit per-IP tidak menjadi yang diukur (semua VU berasal dari satu IP).
bash scripts/dev.sh
export LOADTEST_PASSWORD='<password-uji-lokal>'
bash scripts/k6.sh seed
bash scripts/k6.sh catalog
bash scripts/k6.sh checkout
bash scripts/k6.sh contention
```

`scripts/k6.sh` menjalankan image `grafana/k6` (tidak perlu k6 di host) dengan `--network host` dan
menulis ringkasan ke `load/k6/results/` (diabaikan git).

## Staging

```bash
BASE_URL=https://<host-staging> PROFILE=baseline LOADTEST_PASSWORD=... bash scripts/k6.sh catalog
```

Yang harus beres sebelum angka staging bisa dipakai sebagai baseline:

1. Host dan registry staging (Dokploy) ada, lalu `LoadTestSeeder` dijalankan di container API staging
   dengan env `LOADTEST_*` yang sama.
2. Order + pembayaran: jalur Fake hanya aktif saat `APP_ENV` `local`/`testing`
   (`FakePaymentAvailability`), jadi di staging `checkout.js` berhenti setelah pilih kurir dan
   menghitung `elzara_checkout_order_skipped`, dan `contention.js` gagal di setup. Order di staging
   menunggu gateway sandbox atau keputusan eksplisit untuk menguji jalur Fake di staging.
3. Edge membatasi `/api/` 25 req/s per IP (burst 50, `docker/nginx/edge.conf.template`), dan Laravel
   membatasi `ELZARA_RATE_PUBLIC`/`ELZARA_RATE_WEB` per menit. Dari satu mesin k6, angka di atas batas
   itu mengukur rate limit, bukan kapasitas: jalankan dari beberapa IP atau sepakati batas uji dulu.
4. Hasil (RPS katalog/checkout pada p95 di bawah ambang) menjadi laporan kapasitas ke klien dan masukan
   ambang Waiting Room ADR-009; baru setelah itu P0.24 boleh dicentang.
