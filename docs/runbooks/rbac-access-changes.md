# Perubahan akses RBAC admin

Rujukan: [Doc 11 bagian 4.4 A-B](../11-blueprint-reconciliation.md), [Doc 10 bagian 6.2 dan 8](../10-migration-plan.md), [ADR-012 (RBAC/identitas/dependensi)](../adr/ADR-012-identitas-rbac-dependensi.md).

**Status 2026-11-19:** prosedur dirancang. Pada scaffold, manifest API belum memuat `spatie/laravel-permission`. Jalur mutasi, perintah cache, dan jejak audit belum dibuktikan lewat runtime. Untuk sekarang, operator dapat mencatat permintaan dan bukti baca yang sudah tersedia. Jalankan langkah mutasi setelah implementasi terkait dan verifikasi staging tersedia.

## Deteksi

Mulai prosedur saat ada permintaan perubahan akses yang sah, operator berpindah tugas, izin yang sudah dicabut masih efektif, atau penggunaan hak istimewa tak sesuai catatan. Catat lingkungan, identitas internal target, permintaan/tiket, aksi dan scope yang dimaksud. Untuk insiden kompromi, ikuti juga [runbook kebocoran data](data-breach.md).

## Dampak

Hak efektif guard `admin` bisa berasal dari permission langsung, bundel role, dan batas baris dari `role_scopes`. Flag `is_admin` melewati pemeriksaan izin pada guard `admin`; role `superadmin` adalah bundel permission. Pencabutan satu jalur belum menghilangkan akses jika jalur lain masih memberikan aksi yang sama. Ada risiko izin lama masih terbaca bila cache tidak terinvalidasi.

## Langkah tanggap

### Prasyarat

- Tentukan aksi dan scope dari matriks Doc 06. Catat hak yang seharusnya tetap berlaku agar pemeriksaan bisa mendeteksi pencabutan berlebihan.
- Siapkan sesi operator staging dan objek uji yang mewakili scope terdampak. Pilih endpoint baca saat cukup untuk menguji izin. Untuk izin mutasi, gunakan data staging yang aman dan catat kondisi awalnya.
- Pastikan pengelola punya akses membaca catatan audit dan dapat menghubungkan request ke rilis serta lingkungan yang benar.

### Perubahan akses

1. **Periksa keadaan awal.** Cocokkan target dan lingkungan, guard `admin`, status akun, `is_operator`, `is_admin`, sumber izin dan scope. Simpan ringkasan sebelum perubahan serta alasan persetujuan. Lindungi kredensial dan data pelanggan dari tiket maupun keluaran perintah.
2. **Cabut akses melalui aplikasi.** Gunakan jalur mutasi yang memakai API paket Spatie dan menginvalidasi cache melalui `PermissionRegistrar`. Pilih cabut permission langsung, lepaskan role target, atau ubah scope sesuai permintaan. Perubahan pada bundel bersama berpengaruh pada semua pemegang role dan memerlukan persetujuan sesuai cakupannya. Hindari SQL langsung ke tabel RBAC.
3. **Periksa cache dan otorisasi dari request baru.** Bila perlu reset cache dengan Artisan `permission:cache-reset` pada service API dan lingkungan yang sama setelah memastikan perintah terdaftar. Reset cache hanya menyegarkan evaluasi. Verifikasi bahwa request admin milik target yang masih sah sekarang mendapat penolakan untuk aksi/scope yang dicabut, dan aksi yang seharusnya dipertahankan masih berhasil. Cek sumber izin lain atau flag `is_admin` bila hasilnya berbeda.
4. **Pemberian bypass manual.** Pemilik proyek menyetujui dan memberikan `is_admin` lewat jalur aplikasi yang berjejak. Pastikan target adalah operator aktif dengan 2FA. Simpan nilai sebelum dan sesudah, alasan, serta request ID di `audit_logs` untuk perubahan flag. Gunakan jalur yang sama untuk mencabutnya. Seeder, backfill, dan perubahan bundel role tidak memberi bypass.
5. **Verifikasi pemakaian bypass.** Di staging, gunakan pemeriksaan izin admin yang aman dan memang membutuhkan bypass. Cocokkan `audit_logs.action = rbac.bypass`, `reason` dan `request_id` dengan request tersebut. Periksa juga bahwa flag tidak memberi hak tambahan di storefront. Untuk perubahan berisiko, gunakan jejak append-only `audit_logs` sebagai bukti.

### Skenario verifikasi staging

| Skenario | Bukti yang dicatat |
|---|---|
| Izin langsung dicabut dan tidak diwarisi | Endpoint menolak aksi pada request baru. Aksi kontrol yang tetap diizinkan berhasil. |
| Izin langsung dicabut tetapi masih diwarisi | Keputusan endpoint cocok dengan bundel role yang tersisa. Jika tujuan perubahan adalah menghapus akses efektif, tinjau role atau scope bersama pemilik. |
| Cakupan role dipersempit | Aksi lolos dalam scope yang dipertahankan dan ditolak pada objek di luar scope tersebut. Catat kedua request ID. |
| Bypass diberikan lalu dicabut | Penggunaan bypass di guard admin berjejak. Setelah dicabut, aksi yang sebelumnya membutuhkan bypass kembali ditolak. Storefront tetap mengikuti policy kepemilikan dan status akun. |

### Pemulihan dan pemeriksaan audit

1. Jika hak terhapus terlalu luas, gunakan jalur aplikasi yang sama untuk mengembalikan hak yang disetujui dari catatan awal. Catat alasan pemulihan sebagai perubahan baru, invalidasi cache, lalu ulangi skenario kontrol.
2. Jika hasil berbeda antar-request, bandingkan target, guard, deployment, sumber izin, scope, dan cache yang dipakai setiap proses. Setelah tindakan korektif, ulangi request baru di lingkungan yang sama.
3. Jika audit tidak terlihat, cocokkan request ID, identitas aktor, waktu dan lingkungan dengan log request. Laporkan selisih ke pemilik. Tahan pemberian bypass lanjutan sampai penyebabnya diketahui dan satu percobaan staging menghasilkan jejak yang dapat dirujuk.

## Eskalasi

Hentikan mutasi dan lapor pemilik proyek bila jalur berjejak belum tersedia, izin tetap efektif setelah refresh, target tidak memenuhi syarat, atau catatan audit hilang. Gunakan kanal insiden apabila ada indikasi penyalahgunaan. Jangan mengubah jejak audit untuk menutup temuan.

## Setelah insiden

Catat siapa menyetujui dan menjalankan perubahan, waktu, hasil sebelum/sesudah, hasil verifikasi guard dan scope, serta request ID audit yang relevan. Untuk bypass sementara, catat penanggung jawab dan waktu pencabutan, lalu buktikan izin efektif kembali sesuai kebijakan.
