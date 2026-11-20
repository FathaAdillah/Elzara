# Konfigurasi Nuxt UI

Repo memakai Nuxt 4 dan Nuxt UI v4. Periksa [manifest](../../../../apps/admin/package.json), [lockfile](../../../../apps/admin/package-lock.json), dan [konfigurasi Nuxt](../../../../apps/admin/nuxt.config.ts) sebelum mengubah setup. Pada saat adaptasi, lockfile memuat Nuxt 4.6.4 dan Nuxt UI 4.13.0.

- Daftarkan `@nuxt/ui` di `modules` dan CSS aplikasi di `css`. CSS memuat `@import "tailwindcss";` lalu `@import "@nuxt/ui";`. Nuxt UI mendaftarkan Nuxt Icon dan color mode lewat integrasi modul.
- Gunakan `UApp` di `app/app.vue` untuk locale dan provider overlay. Render halaman melalui `NuxtLayout` dan `NuxtPage`.
- Route dan layout berbasis berkas berada di `app/pages` dan `app/layouts`. Komponen `U` di-auto-import. Gunakan `#components` ketika komponen diperlukan di fungsi render.
- Untuk perubahan aplikasi, jalankan `npm run typecheck` dan `npm run build` dari admin dengan toolchain Node sesuai lockfile dan Dockerfile. Ikuti [panduan Docker](../../../../docker/README.md) jika tugas mencakup build lokal.

[Instalasi resmi untuk Nuxt](https://ui.nuxt.com/docs/getting-started/installation/nuxt) · [Tema dan aset proyek](theming.md)
