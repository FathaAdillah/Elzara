# Tampilan data

- `UAccordion` untuk bagian yang dapat dibuka dengan label jelas. Gunakan pengenal item stabil bila state pembukaan terhubung ke route atau persyaratan alur.
- `UTimeline` untuk kejadian berurutan. Gunakan waktu atau tahap yang sah dari API dan biarkan informasi tetap terbaca tanpa warna.
- `UTree` menampilkan hierarki. Gunakan identitas node stabil dan pertahankan pemisahan antara memilih node dan membuka anaknya bila keduanya tersedia.
- `UUser` menyusun nama, deskripsi, dan avatar. Gunakan hanya atribut profil yang diberikan API. `UScrollArea` cocok untuk konten yang punya batas gulir tersendiri.
- Gunakan `UCarousel` atau `UMarquee` ketika manfaat interaksinya jelas. Pastikan konten utama dapat diakses dengan input keyboard dan preferensi reduced motion.

## Grafik tren Snowego

Gunakan [OperationsTrendChart.client.vue](../../../../apps/admin/app/components/OperationsTrendChart.client.vue) sebagai contoh integrasi Vue ApexCharts di Nuxt. Muat chart di sisi klien, jaga ruang placeholder saat menunggu, dan berikan caption atau ringkasan data. Saat filter berubah, update seri dari sumber data yang sama. Tampilkan keadaan kosong jika endpoint belum menyediakan data.

[Accordion](https://ui.nuxt.com/docs/components/accordion) · [Timeline](https://ui.nuxt.com/docs/components/timeline) · [Tree](https://ui.nuxt.com/docs/components/tree) · [ScrollArea](https://ui.nuxt.com/docs/components/scroll-area) · [Vue ApexCharts](https://apexcharts.com/docs/vue-charts/)
