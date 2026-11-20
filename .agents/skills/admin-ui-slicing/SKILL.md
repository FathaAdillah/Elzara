---
name: admin-ui-slicing
description: Terjemahkan mockup, screenshot, atau spesifikasi menjadi halaman admin Snowego berupa daftar, detail, formulir, pengaturan, atau dashboard dengan komponen asli Nuxt UI.
---

# Slicing UI admin Snowego

Ambil hierarki informasi, kepadatan ruang, dan perilaku dari referensi. Gunakan identitas Snowego, token tema yang sudah tersedia, dan komponen Nuxt UI. [Panduan tema](../nuxt-ui/references/theming.md)

## Pilih struktur layar

| Layar | Susunan |
|---|---|
| Daftar atau antrean | Judul dan aksi utama, filter yang dibutuhkan, tabel, dan navigasi halaman sesuai kontrak API |
| Formulir | Kelompok field dengan label, pesan validasi dekat field, aksi simpan dan batal |
| Detail | Judul atau pengenal record, ringkasan utama, lalu bagian atau tab jika informasinya cukup banyak |
| Dashboard | Judul, filter periode yang berfungsi, metrik yang tersedia, tren, dan aktivitas yang relevan |
| Pengaturan | Kartu per kelompok pengaturan dan status penyimpanan yang jelas |

## Implementasi

1. Periksa [layout admin](../../../apps/admin/app/layouts/admin.vue), [dashboard](../../../apps/admin/app/components/OperationsDashboard.vue), atau [login](../../../apps/admin/app/pages/login.vue) untuk mengikuti pola yang berjalan.
2. Tempatkan halaman pada routing Nuxt dan gunakan layout yang sudah ada. Pakai `UTable`, `UForm`, `UCard`, `UEmpty`, atau overlay sesuai struktur layar. Ekstrak komponen ketika blok dipakai ulang atau punya tanggung jawab mandiri yang jelas.
3. Hubungkan aksi ke kontrak API yang tersedia dan tunjukkan status aksi secara jujur. Gunakan konfirmasi untuk operasi berisiko ketika alurnya membutuhkannya. Tampilkan error field dan status request di tempat pengguna bisa memperbaikinya. [Pola form](../nuxt-ui/references/component-forms.md) · [Pola overlay](../nuxt-ui/references/component-overlays.md)
4. Susun label, angka dan tanggal dalam Bahasa Indonesia. Pakai ikon lokal, label aksesibel untuk tombol ikon, dan teks pendamping saat status memakai warna. Periksa fokus keyboard dan overflow di layar kecil.
5. Jalankan typecheck, build, dan uji UI yang relevan jika tugas mengubah aplikasi. Verifikasi keadaan awal, interaksi utama, error yang bisa terjadi, dan keterbacaan dalam mode terang maupun gelap bila berpengaruh.

Untuk route, sesi dan otorisasi, baca [frontend-admin-vue](../frontend-admin-vue/SKILL.md). Untuk API komponen, pilih referensi dari [nuxt-ui](../nuxt-ui/SKILL.md).
