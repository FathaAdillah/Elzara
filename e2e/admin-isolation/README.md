# Isolasi host sesi admin

Uji Chromium mandiri (tidak membutuhkan branch harness P0.23, Nuxt/Next, atau nginx).
Proxy lokal meneruskan `Host` asli ke Laravel dan hanya menyediakan halaman kosong untuk
menjalankan fetch browser. PostgreSQL 16 dan Redis 7 harus tersedia di loopback;
database default `elzara_test` dengan user/password `elzara`. Gunakan database pengujian
khusus: runner menjalankan migrasi dan menghapus dua akun fixture `isolation-*@example.test`.

```sh
touch apps/api/.env
cd e2e/admin-isolation
npm ci
npx playwright install --with-deps chromium
npm test
```

Port 3088/3089 harus kosong. `DB_*`, `REDIS_*`, dan `APP_KEY` dapat dioverride lewat env.
Server menetapkan APP_ENV=local, ADMIN_2FA_ENABLED=false, cookie non-Secure untuk HTTP
localhost, dua origin tetap, dan Redis nyata. Operator dibuat melalui command interaktif
`identity:admin:create` dengan CommandTester, bukan bypass SQL untuk akun operator.
Tes API `AuthSecurityGatesTest` melengkapi jalur TOTP + cookie Secure; tes browser ini
tidak mengklaim pengujian UI Nuxt, TLS/edge produksi, atau pembersihan cookie domain lama.
