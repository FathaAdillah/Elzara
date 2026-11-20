# Formulir Nuxt UI

## UForm dan UFormField

Gunakan `UForm` dengan `state` reaktif dan `validate` atau schema Standard Schema yang memang dipakai paket. Untuk `@submit`, `FormSubmitEvent<T>` memakai tipe output validasi sebagai `T`. Nama `UFormField` harus cocok dengan field atau path state agar label dan error terhubung.

- Letakkan tombol simpan `type="submit"` di dalam form. Tandai permintaan yang masih berjalan dan cegah pengiriman ulang bila operasi sedang diproses.
- Tampilkan error Laravel 422 dari pengiriman di `form.setErrors([{ name, message }])` jika nama field cocok. Gunakan `UAlert` untuk kegagalan tingkat permintaan. Untuk error bertingkat, cocokkan path dengan kontrak dan gunakan `error-pattern` jika satu field menaungi beberapa path.
- Atur tipe input, autocomplete, label, serta akses keyboard. Pertahankan kata sandi, TOTP, dan recovery code sesuai bentuk yang diterima endpoint. Lihat [contoh login aktual](../../../../apps/admin/app/pages/login.vue).

## Kontrol field

- `UInput` untuk teks satu baris, `UTextarea` untuk isi panjang, dan `UInputNumber` jika perlu kontrol angka. Pertahankan unit uang dari kontrak ketika mengirim nilai.
- `USelect` untuk opsi tetap. `USelectMenu` untuk opsi yang dicari, dikelompokkan, atau dimuat bertahap. Atur `value-key` dan `label-key` sesuai objek. Selaraskan tipe model dengan tipe nilai item serta opsi kosong yang didukung versi terpasang.
- Gunakan `v-model:search-term` dan `ignore-filter` untuk pencarian `USelectMenu` yang dilakukan server jika kontrak mendukung pencarian. Abaikan hasil request lama setelah kata kunci berubah.
- Pilih `UCheckbox` atau `USwitch` sesuai makna aksi, `URadioGroup` untuk pilihan eksklusif, dan `UFileUpload` untuk pemilihan file. Kriteria penerimaan dan batas file mengikuti API.

[Form](https://ui.nuxt.com/docs/components/form) · [FormField](https://ui.nuxt.com/docs/components/form-field) · [Input](https://ui.nuxt.com/docs/components/input) · [SelectMenu](https://ui.nuxt.com/docs/components/select-menu)
