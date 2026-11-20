# Dokumen 07 — Profil Bisnis & Aturan Flow Sumber

> **Catatan status (ADR-003 K-4 · 2026-11-17).** Dokumen ini adalah **rekaman observasi teks publik** (profil bisnis & aturan flow yang diumumkan situs referensi), bukan dokumen keputusan. Fakta yang dicatat di sini **berlaku apa adanya sesuai yang teramati** dan tidak diubah karena rujukan arsitektur berganti ke Blueprint. Yang berubah adalah **keputusan** arsitektur — kini bersumber dari Blueprint (`SNOWEGO_MICROSERVICES_BLUEPRINT.md` v1.0) dan direkonsiliasi butir-per-butir di **Doc 11**. Aturan operasional F1–F11 tetap menjadi **masukan kami**; bagaimana ia diwujudkan secara teknis mengikuti keputusan di Doc 11.

Tanggal: 2026-11-16 · Sumber: halaman publik `/tentang-kami` dan `/faq` milik situs referensi, dibaca sebagai teks publik.
Bentuk dokumen: **rangkuman faktual dalam kata-kata kami** (identitas, model layanan, aturan operasional) — bukan salinan halaman. Kolom terakhir adalah konsekuensi untuk produk kita.

## 1. Identitas bisnis

| Aspek | Isi |
|---|---|
| Nama bisnis | **Snowe GO** |
| Kategori | layanan belanja barang pilihan dari luar negeri (bukan sekadar toko ritel: ada jasa pesan + procurement) |
| Kanal kontak publik | email + WhatsApp bisnis, tercantum di `/kontak` (tidak kita simpan nomor/alamatnya ke repo) |
| Halaman identitas | `/tentang-kami` (profil, layanan, cara kerja, transparansi, rujukan baca sebelum belanja) |
| Titik penjualan utama yang mereka bangun | **kejelasan posisi**: buyer selalu tahu tahap pembayaran, tahap perjalanan barang, dan apa tindakan berikutnya |

Sudut pandang bisnisnya penting untuk kita: mereka menjual **kepastian informasi** di transaksi yang panjang dan tidak pasti (impor bertahap), bukan hanya menjual barang. Fitur yang mendukung janji itu (pemisahan status, rincian tagihan per jenis, snapshot alamat, verifikasi manual) adalah konsekuensi, bukan hiasan.

## 2. Model layanan (3 jalur)

| Jalur | Cara kerja | Biaya impor (TAX/EMS) |
|---|---|---|
| **Siap Kirim Indonesia** (ready stock) | barang sudah di dalam negeri, proses langsung | **tidak ada** — barang sudah di Indonesia, jadi tagihan TAX/EMS tidak dibuat |
| **Pre-order / group order** | mengikuti periode PO dan perjalanan batch | ya, ditagih terpisah saat relevan |
| **Request produk** | buyer mengajukan → admin tinjau → penawaran individual → berlaku sendiri, **tidak menunggu close PO** | ya, bila barang dari luar negeri |

Dua aturan yang muncul dari FAQ dan langsung mengubah desain kita:
1. **Request berjalan sendiri.** Ia tidak ditahan oleh tutupnya batch PO lain — jadi jalur request tidak boleh memaksa adanya batch aktif.
2. **Tagihan impor hanya untuk jalur impor.** Guard tipe invoice per lane, bukan global.

## 3. Aturan operasional eksplisit yang mereka umumkan

Rangkuman aturan (fakta operasional, bukan kutipan):

| # | Aturan | Dampak ke kita |
|---|---|---|
| F1 | Status **pembayaran** dan status **perjalanan barang** sengaja dipisah; "lunas" ≠ "barang siap" | sudah kita adopsi sebagai `payment_status` vs `fulfillment_status` (dahulu `money_status`, diganti oleh B-3; Doc 01 §6, delta D4; nilai kanonik: Doc 11 §8.3) — kini terkonfirmasi sebagai pilihan desain, bukan tebakan |
| F2 | Status lunas hanya berubah setelah **callback resmi gateway**; buyer dilarang membayar ulang sebelum status diperiksa | penguat aturan U-webhook + empty-state "menunggu verifikasi" di Doc 02 §4 |
| F3 | Jenis tagihan yang bisa ada: pembayaran penuh, DP, pelunasan, TAX, EMS, **gabungan TAX/EMS**, dan biaya tambahan yang dijelaskan admin | melengkapi daftar tipe invoice ADR-002 (`intl_fees` perlu dipecah atau diberi sub-tipe + label yang bisa dijelaskan) |
| F4 | Order berbasis DP **tidak bisa dikirim** sampai semua kewajiban (pelunasan + biaya impor) selesai | gerbang kelayakan kirim = AND atas invoice wajib, bukan cek satu kolom `paid` |
| F5 | **Satu buyer yang belum melunasi tidak menahan seluruh batch**; batch tetap jalan, buyer yang sudah tuntas boleh lanjut saat barangnya siap | ⚠️ mengoreksi asumsi lama kami (advance per batch) → maju per akun/buyer, bukan serempak |
| F6 | Pesanan jadi siap kirim ketika: barang tersedia **+** tagihan wajib lunas **+** packing selesai; baru metode fulfillment diaktifkan | spesifikasi guard "Ready To Ship" yang bisa langsung jadi state-machine test |
| F7 | Pengiriman punya **dua metode**: kurir yang dipantau di website, atau **checkout via Shopee** (kode khusus, lacak dilakukan di sisi Shopee setelah verifikasi) | modul `MarketplacePurchase` (jalur beli-atas-nama; dahulu bernama "Procurement", diganti oleh Doc 11 B-4) bukan opsi eksotis — ini jalur reguler dengan UI & aturan sendiri |
| F8 | Kode Shopee **bukan bukti checkout berhasil**: kode dipakai saat checkout → buyer melapor di website → admin memverifikasi; **1 kode = 1 paket**, tidak bisa dipakai ulang setelah terverifikasi | mesin status + aturan single-use + anti-klaim-ganda untuk jalur `MarketplacePurchase` (Doc 06 §3.6) |
| F9 | Stok, harga, kurs, variasi, dan biaya dapat **berubah sebelum pembelian dikonfirmasi**; perubahan material diberitahukan sebelum dilanjutkan | butuh konsep **masa berlaku harga** + alur re-konfirmasi/rekonsent (menyentuh cart, quote, dan invoice pelunasan/biaya impor) |
| F10 | Alamat disimpan sebagai **snapshot saat checkout**; edit profil tidak mengubah riwayat order | aturan skema: `order_addresses` (tidak boleh FK-live ke `addresses`) |
| F11 | Pembayaran terpotong dua kali → jangan buat pembayaran baru, hubungi admin dengan **nomor invoice + referensi transaksi** untuk diverifikasi lalu diproses per kebijakan refund | halaman "lapor pembayaran ganda" dengan input invoice no + ref, dan antrean verifikasi Finance |

## 4. Yang mereka **tidak** ungkapkan (dan jadi pertanyaan untuk klien kita)

Besaran deposit dan aturan hangusnya · tanggal/jumlah periode PO per batch · ETA khas per negara asal · siapa yang menanggung selisih kurs · bagaimana TAX/EMS dihitung (riil per kg/pajak atau flat per batch) · batas waktu laporan kendala · skema penanganan kerusakan/hilang di leg internasional · apakah harga request sudah termasuk biaya jasa.
Delapan hal ini menentukan angka & copy di UI; tidak bisa disimpulkan dari halaman publik, dan tidak akan saya karang.

## 5. Implikasi ke rencana kita

1. **Nilai jual yang harus kita tiru secara fungsi** = transparansi posisi pesanan + pemisahan uang vs barang. Fitur pendukung yang wajib kelas satu: timeline per lane, daftar tagihan dengan jatuh tempo, status "menunggu verifikasi pembayaran", snapshot alamat.
2. **Modul `MarketplacePurchase` naik status** (dahulu bernama "Procurement" di dokumen ini; nama itu kini kembali ke makna supplier PO per Doc 11 B-4) dari "opsional" menjadi jalur reguler dengan aturan kode (single-use, verifikasi admin, lacak di platform lain). Kalau klien kita tidak berjualan via marketplace pihak ketiga, matikan flag — tapi biarkan desainnya siap.
3. **Batch advance per-buyer (F5)** mengubah logika pelepasan PO pada fase batch/pre-order (pemetaan sprint akhir belum diputus — Q-1, Doc 11 §5): jangan rancang "satu batch jalan bareng".
4. **Masa berlaku harga & re-konsen (F9)** menambah satu state di invoice/quote + satu notifikasi, dan satu baris di T&C kita.

## 6. Batas

Dokumen ini memakai informasi publik untuk memahami **model bisnis kategori ini**. Identitas, merek, kontak, dan teks halaman Snowe GO tetap milik mereka; tidak dipakai sebagai aset, copy, atau nama untuk produk kita. Brand yang kita bangun adalah brand klien kita sendiri.
