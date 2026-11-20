# Layout dashboard

Ikuti [layout admin](../../../../apps/admin/app/layouts/admin.vue). `UDashboardGroup` menaungi `UDashboardSidebar` dan `UDashboardPanel`. Panel menyediakan navbar dan area konten, sedangkan layout Nuxt menempatkan halaman melalui `slot`.

- `UDashboardGroup` menerima `unit` dan `storage-key`. Ukuran sidebar harus memakai unit yang sama agar mode ciut dan state tersimpan bekerja.
- `UDashboardSidebar` menyediakan slot `header`, default, dan `footer` dengan state `collapsed`. Letakkan tautan merek di header, navigasi di tengah, dan menu akun di footer. Gunakan `ui.root` untuk rail desktop dan `ui.content` untuk panel ponsel bila memberi warna khusus.
- `UDashboardNavbar` punya toggle bawaan. Jika kustomisasi, gunakan slot `toggle` untuk satu aksi yang sesuai ukuran layar. Layout saat ini memakai `UDashboardSidebarCollapse` di desktop dan `UDashboardSidebarToggle` di ponsel.
- Panel dashboard mengelola overflow. Periksa tautan fragmen terhadap area yang benar-benar menggulir dan uji sidebar dalam mode lebar, ciut, serta drawer. Jaga label yang dapat dibaca pembaca layar saat hanya ikon yang terlihat.
- `USidebar` cocok untuk panel navigasi mandiri. Pertimbangkan `USplitter` hanya jika pengguna perlu menggeser batas dua panel.

[DashboardGroup](https://ui.nuxt.com/docs/components/dashboard-group) · [DashboardSidebar](https://ui.nuxt.com/docs/components/dashboard-sidebar) · [DashboardNavbar](https://ui.nuxt.com/docs/components/dashboard-navbar) · [DashboardPanel](https://ui.nuxt.com/docs/components/dashboard-panel)
