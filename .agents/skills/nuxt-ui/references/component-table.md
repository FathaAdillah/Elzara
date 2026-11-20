# Tabel admin

`UTable` memakai TanStack Table. Ketik kolom sebagai `TableColumn<Row>[]` dari `@nuxt/ui`. Gunakan `accessorKey` atau `id` yang stabil. Isi sel dapat dirender dari `row.original` melalui slot `#<column>-cell`. Untuk fungsi render, impor komponen melalui `#components` atau `resolveComponent`.

- Gunakan `loading` dan slot `empty`. Bedakan data kosong dari request gagal. Ikon aksi baris perlu nama aksesibel. Pakai `UDropdownMenu` jika beberapa aksi harus ditawarkan sekaligus.
- Gunakan `v-model:sorting`, `v-model:global-filter`, `v-model:column-filters`, atau `v-model:column-visibility` ketika state tabel perlu dipakai di luar tabel. Jika API bertanggung jawab atas filter atau sort, terapkan input di query server agar berlaku untuk seluruh hasil.
- Ikat row selection ke identitas record melalui `get-row-id` ketika pilihan harus bertahan setelah data berubah. `v-model:expanded` dan slot `expanded` dapat menampilkan detail singkat.
- Pakai header sticky dan pengguliran/virtualisasi hanya bila ukuran data memerlukannya. Periksa representasi kolom penting pada ponsel dan fokus keyboard pada kontrol di sel.

## Pagination

- Untuk hasil kecil yang sudah lengkap di klien, `v-model:pagination` dan `getPaginationRowModel()` dari TanStack dapat menghubungkan tabel dengan `UPagination`. `tableApi` memakai `pageIndex` nol-basis, sedangkan UI pagination satu-basis.
- Untuk hasil server, berikan ke `UTable` baris dari halaman yang sudah diiris API. Snowego memakai `cursor` dan `limit` pada endpoint cursor. Perlakukan cursor sebagai nilai opaque, reset state cursor ketika filter berubah, dan tampilkan navigasi berdasarkan kelanjutan yang diberikan server. Gunakan angka halaman hanya ketika kontrak menyediakannya.

[UTable](https://ui.nuxt.com/docs/components/table) · [Pagination](https://ui.nuxt.com/docs/components/pagination) · [Kontrak API Snowego](../../../../docs/12-api-contract.md)
