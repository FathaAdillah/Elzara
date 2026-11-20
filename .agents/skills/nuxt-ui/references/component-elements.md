# Elemen UI admin

- `UButton` memakai `color`, `variant`, `size`, label, serta ikon sesuai tujuan. Pakai `to` untuk navigasi dan `type="submit"` untuk pengiriman form. Tombol yang hanya punya ikon memerlukan `aria-label`. Gabungkan state loading dengan perlindungan terhadap pengiriman ganda yang relevan.
- `UBadge` menampilkan status dengan teks serta warna semantik. `success` untuk keberhasilan, `warning` untuk menunggu atau kondisi yang perlu perhatian, `error` untuk gagal, `info` untuk pemrosesan, dan `neutral` untuk status tanpa makna urgensi. Pemetaan mengikuti enum domain pada API.
- `UAvatar` memakai foto dari sumber sah bila tersedia. Gunakan inisial atau ikon lokal sebagai fallback dan beri nama aksesibel pada tombol yang memuatnya.
- `UCard` memberi permukaan bagi ringkasan atau kelompok field melalui slot `header`, body, dan `footer`. Gunakan `USeparator` untuk batas yang berarti. Periksa kepadatan kartu pada ukuran layar kecil.
- `UAlert` cocok untuk pesan menetap, `UEmpty` untuk hasil kosong, dan `USkeleton` ketika pemuatan akan mengganti area berketinggian serupa. Beri tindakan yang masuk akal jika pengguna dapat memulihkan keadaan.

[Button](https://ui.nuxt.com/docs/components/button) · [Badge](https://ui.nuxt.com/docs/components/badge) · [Avatar](https://ui.nuxt.com/docs/components/avatar) · [Card](https://ui.nuxt.com/docs/components/card) · [Empty](https://ui.nuxt.com/docs/components/empty)
