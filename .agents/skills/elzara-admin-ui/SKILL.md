---
name: elzara-admin-ui
description: Implementasikan atau tinjau UI login, challenge 2FA, tema lavender, serta dashboard operasional Snowego di apps/admin memakai Nuxt UI.
---

# UI admin Snowego

Gunakan runtime Nuxt 4 yang sekarang berjalan, dengan dashboard pada `/` dan login pada `/login`. Baca [arsitektur frontend admin](../frontend-admin-vue/SKILL.md) untuk layout, sesi dan kontrak API. Untuk desain halaman baru, gunakan [admin-ui-slicing](../admin-ui-slicing/SKILL.md).

## Identitas visual

Pertahankan palet lavender Snowego. Mode terang memakai `#FFFFFF` pada permukaan utama, `#C0C9EE` pada sidebar, `#A2AADB` pada menu aktif, `#898AC4` sebagai aksen dan `#202344` sebagai tinta gelap. Gunakan token dari [CSS admin](../../../apps/admin/app/assets/css/main.css) untuk varian terang dan gelap. Pakai logo lokal [elzara-mark.png](../../../apps/admin/public/brand/elzara-mark.png) dan ikon Lucide yang masuk dalam build. [Tema dan aset lokal](../nuxt-ui/references/theming.md)

## Login operator

- Desktop memakai panel identitas dan kartu form terpusat. Ponsel memakai identitas ringkas di atas form satu kolom. Gunakan `UCard`, `UForm`, `UFormField`, `UInput`, dan `UButton`.
- Pertahankan autocomplete, validasi, kontrol tampilkan kata sandi, status pengiriman, serta pesan error yang dapat ditindaklanjuti. Tantangan 2FA mengikuti respons Laravel. Gunakan endpoint verifikasi sesi sebelum menavigasi ke dashboard.
- Buat widget verifikasi pihak ketiga hanya ketika sudah ada integrasi backend dan pengguna meminta fungsinya. Gunakan alur 2FA dan logout sesuai kontrak. [Kontrak API](../../../docs/12-api-contract.md)

## Dashboard

Pertahankan sidebar yang dapat diciutkan, navigasi ponsel, toggle tema, notifikasi, dan menu akun melalui layout admin bersama. Gunakan kartu metrik dan grafik hanya jika data tersedia. Layar operasional harus punya keadaan kosong atau gagal yang jelas. [Layout dashboard Nuxt UI](../nuxt-ui/references/component-layout-dashboard.md)

Periksa login dan sesi dengan data uji yang sah, tombol ikon saat render awal dan setelah interaksi, fokus keyboard, serta drawer ponsel. Untuk perubahan aset, uji saat cache dan akses CDN dinonaktifkan.
