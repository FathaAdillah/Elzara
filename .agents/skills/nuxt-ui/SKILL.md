---
name: nuxt-ui
description: Gunakan saat memilih, mengonfigurasi, atau memperbaiki komponen Nuxt UI di Snowego, termasuk form, tabel, navigasi, overlay, layout dashboard, tema, dan ikon lokal.
---

# Nuxt UI

Periksa versi yang terkunci di `apps/admin/package-lock.json` dan contoh aktif di `apps/admin/app`. Admin Snowego memakai modul `@nuxt/ui` pada Nuxt 4, Reka UI, dan Tailwind CSS 4. Gunakan dokumentasi Nuxt UI untuk props, slot, tipe, dan versi minimum. [Dokumentasi komponen](https://ui.nuxt.com/docs/components)

Pilih referensi sesuai pekerjaan.

| Pekerjaan | Referensi |
|---|---|
| Konfigurasi modul dan titik masuk Nuxt | [Getting started](references/getting-started.md) |
| Palet, warna semantik, tema gelap, ikon dan logo lokal | [Theming](references/theming.md) |
| Dashboard shell, sidebar dan navbar | [Layout dashboard](references/component-layout-dashboard.md) |
| Breadcrumb, tab, grup menu dan pagination | [Navigation](references/component-navigation.md) |
| Validasi form, field, select, dan input | [Forms](references/component-forms.md) |
| Tombol, avatar, kartu, alert dan empty state | [Elements](references/component-elements.md) |
| Modal, slideover, dropdown, popover, tooltip dan toast | [Overlays](references/component-overlays.md) |
| Tabel, filter, pemilihan baris dan pagination server | [Table](references/component-table.md) |
| Tampilan ringkasan, accordion, timeline dan chart | [Data display](references/component-data.md) |

Gunakan komponen asli dengan awalan `U`, tipe yang diekspor dari `@nuxt/ui`, dan opsi `ui` untuk slot tema yang tersedia. Pertahankan `UApp` pada titik masuk untuk provider komponen. Pakai ikon lokal yang diketahui saat build. Periksa komponen dalam mode terang dan gelap apabila perubahan menyentuh tema atau keterbacaan.

Untuk arsitektur serta kontrak API admin, gunakan [frontend-admin-vue](../frontend-admin-vue/SKILL.md). Untuk struktur layar dari desain atau spesifikasi, gunakan [admin-ui-slicing](../admin-ui-slicing/SKILL.md).
