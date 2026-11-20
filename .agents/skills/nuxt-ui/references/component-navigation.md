# Navigasi

- `UNavigationMenu` memakai `NavigationMenuItem`. Gunakan `to` untuk route nyata, `children` untuk grup, dan state aktif yang mengikuti route atau fragmen. Untuk sidebar vertikal, mode `collapsed` dapat memakai popover berisi anak menu dan tooltip pada tautan langsung. Periksa fokus serta keterbacaan label saat ciut.
- `UBreadcrumb` menunjukkan posisi layar. Gunakan item bertautan untuk tingkat yang memang bisa dikunjungi dan deskripsikan item aktif melalui label.
- `UTabs` mengelompokkan panel yang setara. Pilih nilai stabil untuk `v-model` dan pertahankan state tab sesuai kebutuhan layar. Gunakan `UStepper` untuk langkah berurutan.
- `UPagination` memakai `page` satu-basis, `items-per-page`, dan `total` apabila kontrak menyediakan jumlah halaman. Untuk endpoint cursor Snowego, tampilkan navigasi sebelum/berikut sesuai cursor yang tersedia. [Pola tabel](component-table.md)
- `UCommandPalette` berguna untuk daftar aksi yang dapat dicari. Sertakan hanya aksi dengan tujuan dan perilaku yang tersedia bagi akun saat ini.

[NavigationMenu](https://ui.nuxt.com/docs/components/navigation-menu) · [Breadcrumb](https://ui.nuxt.com/docs/components/breadcrumb) · [Tabs](https://ui.nuxt.com/docs/components/tabs) · [Pagination](https://ui.nuxt.com/docs/components/pagination)
