# ADR-006 — Gateway pembayaran dan semantik settlement/rekonsiliasi

Tanggal: 2026-11-17 · Status: **Proposed — diblokir jawaban klien (Doc 11 §6 Q-7)**; kerangka adapter dapat diterima, semantik uang tidak · Menangani Blueprint §18 butir 5 (Doc 11 §3: "sebagian — ADR-002, Doc 01 U5; blocker klien Doc 03 §9") · Rujukan induk: Blueprint §8.4 butir 5, §16, §17 Phase 1 (ADR-003)

## Konteks

Blueprint §18 menuntut keputusan "payment gateway dan semantik settlement/rekonsiliasi" sebelum coding berat, tetapi akun merchant, kontrak komersial, dan daftar metode bayar adalah **milik klien** (Doc 03 §9: "Merchant account + SKA/SNI gateway" sebagai jalur paralel; Doc 11 Q-7). Dokumen lama kita sudah mengambil sebagian keputusannya: ADR-002 memindahkan kunci uang dari order ke `invoices` (satu invoice = satu jatuh tempo = satu reminder), dan aturan U5 menetapkan rekonsiliasi harian ledger vs laporan settlement provider tanpa auto-adjust.

Yang belum diputuskan — dan tidak bisa kami putuskan sendiri — adalah empat semantik yang justru menentukan bentuk skema dan alur pengecualian: kapan settlement dianggap sah, apakah pembayaran parsial atas satu invoice itu ada, berapa jendela refund, dan bagaimana biaya provider dilaporkan. Menuliskan nama gateway sebagai "keputusan" sekarang akan menjadi keputusan palsu: driver yang salah pilih berarti menulis ulang adapternya, bukan mengganti konfigurasi.

Karena itu ADR ini **dipecah dua**: bagian yang bisa dikunci hari ini (K-1…K-5) dan bagian yang menunggu (T-1…T-4).

## Keputusan — yang bisa dikunci sekarang

**K-1. Adapter, bukan integrasi langsung.** Modul Payments mengekspos satu kontrak `PaymentProvider` (buat tagihan/intent, tanya status, refund, verifikasi callback). Setiap gateway adalah satu driver di `Infrastructure/` yang mengimplementasikannya. Tidak ada satu pun kode domain yang menyebut nama provider; kolom `provider` di `payment_intents` adalah data, bukan cabang `if`.

**K-2. Satu driver aktif di MVP.** Kandidat yang tercatat di dokumen lama (Midtrans, Xendit, Tripay) tetap kandidat; **pilihannya adalah Q-7**. Driver kedua menyusul lewat kontrak yang sama, tanpa QA ulang menyeluruh, justru karena K-3.

**K-3. Suite kontraktor yang sama untuk setiap driver.** Blueprint §16 dan Doc 01 §11 menuntut "suite yang sama harus lolos untuk setiap driver". Suite itu memuat: verifikasi tanda tangan, penolakan replay, dedup `provider_event_id`, status transisi yang sah vs tidak sah, refund penuh/parsial, callback sebelum intent kedaluwarsa, dan callback setelah kedaluwarsa (jalur `paid_stock_exception` di ADR-005). Suite ini adalah gerbang penggantian driver, bukan dokumen tes terpisah.

**K-4. Rekonsiliasi adalah fitur, bukan laporan ad-hoc.** Pekerjaan terjadwal membandingkan `payment_ledger` (append-only, kunci invoice — ADR-002) dengan berkas settlement provider, menulis `reconciliation_runs` + `reconciliation_items`, dan memasukkan selisih ke **dashboard pengecualian** yang sama dengan antrean `paid_stock_exception`. Tidak ada `auto-adjust`: setiap koreksi punya aktor, alasan, dan jejak audit (aturan U5 + Blueprint §8.4 butir 5).

**K-5** Tidak ada data kartu di sisi kami: pembayaran diproses di hosted/redirect gateway sehingga cakupan PCI tetap minimal (Blueprint §13), dan webhook masuk lewat rute terisolasi dengan verifikasi tanda tangan, dedup, ACK cepat, kerja berat async (ADR-004 butir 7).

## Yang **tidak** bisa diputuskan tanpa klien

| # | Pertanyaan | Kenapa teknis saja tidak cukup | Yang berubah kalau dijawab |
|---|---|---|---|
| T-1 | **Waktu settlement**: pada jam berapa laporan provider tersedia, dan status apa yang boleh dianggap "uang masuk" — capture, settlement, atau keduanya? | Menentukan apakah pelunasan PO dipicu capture atau settlement; menentukan definisi "lunas" yang dilihat customer dan dasar report umur piutang | Bentuk `payment_status`, job rekonsiliasi (hari-H vs hari-H+n), dan ambang reminder |
| T-2 | **Semantik pembayaran parsial**: apakah satu invoice boleh diterima sebagian, dan apakah satu pembayaran boleh membiayai beberapa invoice sekaligus? | Aturan A3 di ADR-002 (pembayaran gabungan dengan alokasi per invoice) **hanya sah kalau gateway mendukungnya**; tidak semua driver mendukung split/allocate | Perlu/tidaknya penyimpanan alokasi pembayaran per invoice (satu tabel tambahan di Doc 09), bentuk halaman "bayar semua tunggakan", dan jalur refund parsial |
| T-3 | **Jendela refund**: berapa lama refund bisa diminta, apakah lewat deadline itu hanya via resolusi manual, dan apakah sebagian per item didukung? | Ini kebijakan komersial kontrak gateway + kebijakan retur klien (Doc 02 §8 sudah menjanjikan refund parsial per item) | Status `refund_pending`/`refunded`, SOP support, dan risiko refund setelah kirim |
| T-4 | **Pelaporan biaya**: nominal kotor vs bersih, apakah biaya per transaksi tersedia di laporan, dan siapa yang menanggung biaya itu secara pembukuan | Menentukan apakah biaya provider masuk sebagai baris `order_charges`, penyesuaian `order_adjustments`, atau hanya selisih rekonsiliasi; berdampak pada margin per batch yang dijanjikan Doc 02 §6 | Skema ledger + laporan margin; kalau kotor, setiap selisih harus punya akun koreksi |

## Alternatif yang ditolak

| | Ditolak | Alasan kalah |
|---|---|---|
| A | Menulis integrasi langsung ke satu provider sekarang, adapter nanti | "Nanti" tidak pernah datang untuk kode yang sudah dipakai produksi; hasilnya cabang `if (provider === …)` di domain, dan driver kedua jadi proyek rewrite — persis yang dicegah K-1/K-3 |
| B | Menyembunyikan pilihan di balik flag tanpa suite kontraktor | Tanpa tes yang sama, pergantian driver = QA ulang manual penuh; biaya jatuh pada sprint berikutnya yang tidak menganggarkannya |
| C | Multi-driver aktif sejak MVP (rute pembayaran memilih otomatis) | Beban operasional: tiga set rahasia, tiga dashboard rekonsiliasi, tiga semantik status yang harus dipetakan — untuk manfaat yang belum diminta; Doc 01 §14 sudah mengasumsikan satu gateway aktif |
| D | Auto-adjust ledger saat selisih rekonsiliasi | Melanggar U5 dan menghapus bukti; rekonsiliasi otomatis yang salah koreksi lebih merusak daripada selisih yang dibiarkan terlihat |
| E | Memakai saldo/ledger internal sebagai sumber uang "siap pakai" | Itu jalur double-entry penuh (alternatif C di ADR-002) yang sudah ditolak karena biayanya; kebutuhan klien berhenti di invoice + rekonsiliasi |

## Konsekuensi

Sprint yang menyentuh uang tidak bisa "mulai" tanpa T-1…T-4 terjawab; itu alasan Doc 11 §3 menandai butir Blueprint ini sebagai blocker klien, dan alasan status ADR ini **Proposed**, bukan Accepted. Yang bisa jalan paralel tanpa menunggu: K-1…K-5, termasuk suite kontraktor K-3 yang dijalankan terhadap satu driver sandbox dan terhadap jalur transfer manual yang sudah sah di skema kita (`payment_intents.provider = manual`, penanda-lunas-manual aturan A4 di ADR-002).

Risiko kalau dipaksakan tutup sekarang: tim memilih default teknis untuk settlement dan parsial, lalu skema (`invoice_allocations`, bentuk status) mengunci pilihan itu; membetulkannya setelah data produksi masuk adalah migrasi uang — dilarang aturan M-7 (Doc 10 §1: migrasi tidak pernah menyentuh nilai uang atau status).

Arah balik yang harus dicatat: keputusan di sini ikut menentukan TTL reservasi di ADR-005 (kedaluwarsa intent harus disejalkan dengan jendela bayar gateway) dan pertanyaan CDN/dokumen di ADR-008 (`invoice-documents` privat dipakai juga untuk bukti bayar manual, aturan A4 ADR-002).
