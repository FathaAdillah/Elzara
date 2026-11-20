# Tema, ikon, dan aset lokal

Sumber aktif ialah [CSS admin](../../../../apps/admin/app/assets/css/main.css), [app.config.ts](../../../../apps/admin/app/app.config.ts), dan [nuxt.config.ts](../../../../apps/admin/nuxt.config.ts). Tailwind `@theme static` mendeklarasikan skala `elzara`. Konfigurasi UI memetakan `primary` ke `elzara` dan `neutral` ke `slate`.

| Warna | Peran pada mode terang |
|---|---|
| `#FFFFFF` | Permukaan utama dan kartu |
| `#C0C9EE` | Sidebar |
| `#A2AADB` | Menu sidebar aktif |
| `#898AC4` | Aksen lavender |
| `#202344` | Tinta gelap |

Gunakan utilitas `bg-default`, `bg-elevated`, `border-default`, `text-highlighted`, dan `text-muted` untuk mengikuti token mode aktif. Untuk penyesuaian lokal, gunakan `ui` dengan slot komponen yang didokumentasikan. Ubah `app.config.ts` bila pola itu perlu berlaku di seluruh admin. Gunakan `UColorModeButton` dan kelas `dark:` atau token yang memang memiliki nilai untuk kedua mode.

## Ikon dan logo

- `@iconify-json/lucide` dipasang lokal. Nuxt Icon memakai `mode: 'svg'`, `provider: 'none'`, `fallbackToApi: false`, dan pemindaian `clientBundle` untuk sumber Vue, JS, dan TS.
- Tulis nama `i-lucide-*` secara literal. Jika nama dibentuk dinamis, daftarkan opsi yang didukung dalam `clientBundle.icons` agar tersedia pada build. Periksa nama ikon dalam koleksi lokal jika tampilannya kosong.
- Logo dan favicon memakai [PNG lokal](../../../../apps/admin/public/brand/elzara-mark.png). Gunakan ukuran gambar eksplisit. Beri alt kosong bila nama merek sudah diucapkan oleh teks atau label tautan.
- Saat melihat 404 pada `/api/_nuxt_icon`, periksa bundel dan provider ikon. Prefix `/api/` di edge mengarah ke Laravel. Verifikasi HTML awal dan elemen yang baru muncul setelah interaksi, seperti dropdown akun dan drawer ponsel, dengan cache dan akses eksternal dinonaktifkan.

[Token CSS Nuxt UI](https://ui.nuxt.com/docs/getting-started/theme/css-variables) · [Bundel ikon Nuxt UI](https://ui.nuxt.com/docs/getting-started/integrations/icons/nuxt)
