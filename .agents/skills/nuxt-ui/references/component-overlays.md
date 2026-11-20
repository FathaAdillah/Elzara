# Overlay dan umpan balik

- `UModal` dan `USlideover` dapat memakai `v-model:open`. Default slot dipakai oleh pemicu inline. Tempatkan isi dialog di `#body` dan aksi di `#footer`, atau atur pengganti utuh melalui `#content`. Gunakan `ui.content` untuk lebar. Beri judul yang menjelaskan tugas dan jaga fokus saat dialog terbuka atau ditutup.
- Pada aksi berisiko, tampilkan identitas item dan dampak tindakan. Pertahankan tombol batal, aksi konfirmasi, status loading, serta error pada konteks yang sama. Untuk formulir dengan tombol simpan di footer overlay, kaitkan tombol melalui atribut `form` atau API form yang sesuai.
- `UDropdownMenu` menerima `DropdownMenuItem`, termasuk grup, label, avatar, tautan, dan aksi `onSelect`. Gunakan label untuk tombol ikon. Portal berguna ketika kontainer tabel atau sidebar memotong menu.
- `UPopover` cocok untuk panel berjangkar seperti notifikasi dan filter. Isi panel berada di `#content`. `UTooltip` memberi petunjuk pendek yang melengkapi nama aksesibel.
- `UDrawer` cocok untuk panel tepi di ponsel. `UContextMenu` dapat melengkapi interaksi klik kanan jika aksi juga tersedia dari kontrol yang dapat diakses.
- Gunakan `useToast()` di bawah `UApp` untuk pemberitahuan singkat. Kesalahan field dan kegagalan yang harus diperbaiki pengguna tetap terlihat pada form atau halaman.

[Modal](https://ui.nuxt.com/docs/components/modal) · [Slideover](https://ui.nuxt.com/docs/components/slideover) · [DropdownMenu](https://ui.nuxt.com/docs/components/dropdown-menu) · [Popover](https://ui.nuxt.com/docs/components/popover) · [Toast](https://ui.nuxt.com/docs/components/toast)
