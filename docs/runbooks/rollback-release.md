# Runbook — Rollback rilis yang buruk

**Severity awal:** SEV-1 (aset A-10; ancaman T-27, T-33, T-10)
**Rujukan:** Doc 10 §9 (prioritas rollback; `migrate:status`; `migrate:rollback --step=N`; restore `pre-<release>.dump`), §5.4 (freeze window), §6 (expand → backfill → validasi → cutover → contract), M-4 (migrasi beku setelah merge), M-9, M-13, §10 C5 (`down()` harus benar-benar jalan), §11 butir 10 (rollback didokumentasikan satu kalimat di changelog) · Doc 01 §11.2 (image `elzara/app:${GIT_SHA}` + `:latest`, registry privat, "rollback = tag sebelumnya"; image tidak pernah berisi `.env`), §10.5 (urutan deploy: maintenance mode → drain Horizon → migrate backward-compatible → up → smoke → maintenance off; "migration hanya boleh non-destruktif dalam 1 rilis") · Blueprint §14.3 (CI/CD: … deploy → smoke tests → **rollback**; expand/contract untuk zero-downtime), §19 (DoD: rollback diuji) · Doc 11 §2 (M-1…M-13 tidak diubah Blueprint), **B-1/B-2** (tiga app di-deploy mandiri) · Blueprint §18 butir 9 → **ADR-010** · `docs/threat-model.md` T-27/T-33

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1).

---

## 0. Satu kalimat yang harus benar

> **Mekanisme rollback kita = deploy ulang image kode sebelumnya. Skema Postgres berjalan satu arah dan TIDAK ikut mundur bersama aplikasi.**

Dasarnya bukan kebiasaan, tapi desain: M-4 + M-13 dibuat justru supaya kode lama tetap hidup dengan skema baru (Doc 10 §9: "Alasannya: M-4/M-13 dirancang supaya kode lama tetap hidup dengan skema baru"), dan Doc 01 §11.4 membatasi migrasi dalam satu rilis hanya yang **non-destruktif**. Konsekuensinya: menggulungkan skema bersama kode adalah keputusan **eksepsional**, bukan langkah otomatis dari `migrate:rollback`. Kalau rilis kali ini melanggar prasyarat itu (ada DROP/renama/destruktif di rilis yang sama dengan expand ⇒ melanggar M-9), yang salah adalah rilisnya — tangani dengan restore, bukan dengan rollback skema.

## 1. Deteksi

| Sinyal | Sumber | Ambang |
|---|---|---|
| 5xx naik tajam setelah deploy | metrik Blueprint §15; Sentry | > 1% 5 menit ⇒ rollback |
| smoke test pasca-deploy gagal (login, cari, checkout ke driver `manual`, satu transisi order — Doc 10 §9 langkah 4) | pipeline / manual | ≥ 1 gagal ⇒ rollback |
| `migrate --force` gagal di tengah / meninggalkan skema separuh | log deploy + `migrate:status` | ≥ 1 ⇒ §3.4 (**jangan** langsung `rollback`) |
| error khas "column does not exist" / constraint baru menolak tulis | Sentry + log worker | ≥ 1 ⇒ kode lama & skema baru tidak kompatibel (berarti M-13 dilanggar) |
| Horizon macet setelah deploy (job baru gagal, retry berulang) | `horizon:status`, `failed_jobs` lonjakan | ⇒ `queue-stuck.md` §3.4, lalu putuskan rollback |
| harga/total/nominal salah muncul di order baru | laporan `finance`, metrik nominal | ⇒ **SEV-1 seketika**; jangan lanjut transaksi (lihat §3.2) |
| halaman admin 500 / data tidak muncul padahal API sehat | log `admin` | ⇒ rollback app admin saja (B-1: deploy mandiri) |
| hasil E2E (Playwright 3 alur, Doc 01 §11) merah di staging | CI | ⇒ jangan pernah naik ke produksi; ini rollback "gratis" |

## 2. Dampak

Melanjutkan rilis yang buruk = transaksi salah, stok salah, dan jejak audit yang harus dikoreksi manual. Memutar-skema-secara-terburu-buru = risiko kedua yang lebih besar: `migrate:rollback` yang salah langkah bisa menghapus kolom yang isinya sudah dipakai transaksi baru, dan koreksinya hanya bisa lewat restore (kehilangan data dalam rentang itu). Karena itu urutannya selalu: **hentikan kerugian → mundurkan kode → pertimbangkan skema terakhir, bukan pertama.**

## 3. Langkah tanggap

### 3.1 Putuskan dalam 5 menit: rollback, flag-off, atau hot-fix forward

| Pilihan | Kapan | Biaya |
|---|---|---|
| **A. Flag off** | kerusakan terbatas pada fitur di balik flag (`feature_flags`, `feature_flag_overrides`; Blueprint §4 System) | termurah; tidak menyentuh image maupun skema |
| **B. Rollback kode** (jalur utama runbook ini) | bug di kode rilis, tidak ada data salah yang terlanjur terbentuk | sedang |
| **C. Forward-fix** | bug ringan + rilis tidak bisa ditarik (mis. sudah ada transaksi) | cepat, tapi risiko dua kali deploy |
| **D. Restore `pre-<release>.dump`** | **data sudah bersentuhan** dengan skema/logika salah, atau migrasi meninggalkan DB separuh | paling mahal (RPO nyata = sejak dump) — §3.5 |

`[ verifikasi ]` pilihan dinyatakan tertulis dengan alasan, termasuk "A/C dipilih, B tidak". Jangan mulai §3.3 sebelum §3.2 selesai — mundurkan kode sambil transaksi masih berjalan berarti memperbanyak data yang harus dibersihkan.

### 3.2 Hentikan luka dulu

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan down --render=errors.503       # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:pause                 # belum diverifikasi — drain, jangan bunuh (Doc 01 §11.4)
```

`[ verifikasi ]` tidak ada order/invoice/status baru terbentuk; antrian yang sudah masuk tetap ada (jangan `horizon:clear` untuk `payments`/`inventory`/`orders` — `queue-stuck.md` §3.3); webhook provider tetap diterima dan diarsipkan (provider me-retry; dedup `UNIQUE(provider, provider_event_id)` menahan — `webhook-replay.md`). Kalau `down` dipakai, hitung durasinya dan catat: itu RTO tidak langsung ke pelanggan.

> `php artisan down` bukan sakelar checkout. Kalau fitur flag checkout tersedia, itu yang dipakai (lebih sempit, webhook tidak ikut berhenti) — `cloudflare-waiting-room.md` §3.6.

### 3.3 Mundurkan **kode**: deploy ulang image rilis sebelumnya

Tiga app di-deploy mandiri (B-1), jadi mundurkan hanya yang rusak — jangan satu stack sekaligus kalau cuma admin yang bermasalah.

```bash
# 0) pastikan dulu apa yang TERAKHIR DIPAKAI (bukan apa yang terakhir di-push): baca changelog/CI run, jangan menebak
#    tag image mengikuti SHA rilis: elzara/app:${GIT_SHA} (Doc 01 §11.2) — "rollback = tag sebelumnya"
# 1) tarik tag rilis sebelumnya dari registry privat + jalankan
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml pull app worker
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml up -d app worker                                        # belum diverifikasi di lingkungan ini
# 2) worker: restart supaya tidak ada job yang tertahan dengan kode lama-campur-baru
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:pause                    # belum diverifikasi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml restart worker                                           # belum diverifikasi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:status                    # belum diverifikasi
# 3) frontend Next (app yang rusak saja)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml up -d --force-recreate web                               # belum diverifikasi — nama service lihat compose.prod
```

`[ verifikasi ]`
- image yang jalan = tag rilis sebelumnya (`docker compose ... ps` + inspect tag), **bukan** `:latest`;
- smoke test (login, cari, checkout ke driver `manual`, satu transisi order) hijau;
- `docker compose logs app` tidak memperlihatkan "unknown column"/constraint violation baru;
- tidak ada satu pun `migrate` yang dijalankan di langkah ini.

### 3.4 Konfirmasi keadaan skema dengan `migrate:status` (bukan dengan ingatan)

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan migrate:status                     # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c \
  "SELECT id, migration, batch FROM migrations ORDER BY id DESC LIMIT 20;"                                                # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` tiga pertanyaan terjawab **dengan angka**, dituliskan di log insiden:
1. migrasi rilis bermasalah: **sudah jalan** atau belum? (`Not run` ⇒ mundurkan kode saja, §3.3 cukup)
2. kalau sudah jalan: **berapa** yang berada di belakang titik yang dipakai kode lama? (hitung dari `migrate:status`; **jangan** memakai kolom `batch` sebagai penanda — urutan kita adalah `NNNN`, `batch` hanya mencatat kelompok eksekusi, Doc 10 §4 & §9)
3. apakah file yang jalan itu **expand** (nullable/default aman, M-13) atau **contract/destruktif**? Contract tidak boleh berada di rentang rilis yang sama dengan expand-nya (M-9). Kalau contract ikut jalan di rilis ini, `--step` tidak akan mengembalikan data yang hilang → §3.5.

**Kapan `migrate:rollback --step=N` boleh dipakai — dan hanya boleh:**

| Syarat | Boleh | Tidak boleh |
|---|---|---|
| N = hasil hitung dari `migrate:status`, bukan tebakan | ✅ | — |
| N migrasi paling belakang **belum dipakai kode mana pun** (belum ada request/job yang membaca/menulis bentuk barunya) | ✅ | ❌ kalau kode yang sudah di-rollback atau kode baru sempat menyentuhnya |
| Bentuknya expand saja (tambah kolom nullable/tabel/indeks), tanpa backfill berisi data | ✅ | ❌ kalau sudah ada data di kolom/tabel baru |
| Setiap `down()` benar-benar jalan (dibuktikan CI C5 saat PR, Doc 10 §10) | ✅ | ❌ kalau file itu ada di daftar hitam irreversibel (M-6: `CONCURRENTLY` dll.) |
| Postgres mendukung DDL transaksional per file (M-8, satu file = satu transaksi) | ✅ | ❌ jangan rollback banyak file sambil berharap atomicitas lintas file |

```bash
# hanya kalau SEMUA syarat di atas terpenuhi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan migrate:rollback --step=<N>   # belum diverifikasi — <N> dari migrate:status; Laravel TIDAK punya --step-to (Doc 10 §9)
```

`[ verifikasi ]` `migrate:status` setelah rollback cocok dengan titik yang dihitung di §3.4 butir 2; lalu **jalankan C6-like drift check** (bandingkan `information_schema` + constraint + index vs `docs/schema.snapshot.sql`, Doc 10 §10/C6) dan hasilnya bersih; smoke test §3.3 diulang. Kalau `down()` gagal di tengah: berhenti, jangan mencoba manual satu-satu — lanjut §3.5.

### 3.5 Kapan restore `pre-<release>.dump` adalah jawaban yang **benar**

Dokumen induk sudah memberi jawabannya: "Kalau datanya sudah bersentuhan, jangan mundur: restore dump pra-rilis" (Doc 10 §9). Terjemahan praktisnya — pilih restore bila salah satu ini benar:

- ada transaksi/order/invoice/ledger yang terbentuk **dengan logika atau skema yang salah**;
- migrasi rilis ini menyentuh kolom berisi data (contract/rename/drop), atau `down()`-nya irreversibel (M-6);
- `migrate:rollback` gagal di tengah dan `migrations` kini tidak cocok dengan skema fisik;
- backfill (§6/§7) sudah berjalan di kolom yang akan di-drop;
- nominal/status uang tersentuh (M-7 melarang migrasi menyentuh nilai uang — koreksinya lewat jalur Command, bukan lewat SQL).

Langkah: jalankan `backup-restore-drill.md` §3.6 (dump pra-rilis, bukan dump harian terakhir — titik harus **tepat sebelum** rilis ini), lalu:

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan migrate --force   # belum diverifikasi — harus "Nothing to migrate": tabel `migrations` ikut ter-restore (Doc 10 §9)
```

`[ verifikasi ]` (a) `migrate --force` tidak menjalankan satu file pun; (b) C6/drift bersih terhadap `docs/schema.snapshot.sql`; (c) rekonsiliasi pasca-restore selesai: `payment_ledger` vs settlement provider (U5, tidak ada auto-adjust), order terdampak didaftar nominalnya, stok & kuota dicek invariant (`oversell.md` §3.2), notifikasi/efek yang tidak terkirim di-drive ulang lewat outbox dengan dedup (`queue-stuck.md` §3.5, `webhook-replay.md` §3.3); (d) daftar "apa yang hilang di antara" diserahkan ke klien **sebelum** `php artisan up`.

Konsekuensi yang harus disebut keras-keras: **restore membuang transaksi setelah dump**. Untuk bisnis yang menerima pembayaran via webhook, transaksi yang dibuang itu harus direkonsiliasi manual dengan provider — bukan dianggap tidak pernah terjadi.

### 3.6 Buka kembali

`[ verifikasi ]` sebelum melepas mode perawatan: smoke test hijau dua kali berturut; tidak ada `paid_stock_exception` baru; tidak ada `webhook_receipts.status='received'` yang menua; metrik 5xx kembali ke baseline; Horizon berjalan dengan jumlah supervisor sesuai (`queue-stuck.md` §3.1); flag yang dinaikkan untuk darurat dinyatakan kapan diturunkan dan oleh siapa.

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan up   # belum diverifikasi di lingkungan ini
```

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| perlu `migrate:rollback` di produksi | penanggung jawab rilis + `superadmin` (dua orang, tidak sendiri) | sebelum eksekusi, dengan hitungan §3.4 |
| perlu restore (data dibuang) | **klien** + `finance` | sebelum eksekusi — ini keputusan kehilangan transaksi nyata |
| rilis bermasalah membawa perubahan kontrak API (`packages/api-contracts`, B-2) sehingga dua app tidak kompatibel | penanggung jawab rilis + dev frontend | segera; mundurkan **ketiganya** bersama |
| indikasi image/dependency buruk dari upstream (T-27) | penanggung jawab rilis + pemilik CI | segera; jangan install ulang tanpa lockfile ter-review |
| rollback menyentuh webhook gateway (endpoint/secret berubah) | pemilik akun gateway (**klien**, Q-7) + `finance` | sebelum `up`, agar tidak ada event yang hilang |
| kerugian transaksi akibat downtime/mode perawatan melewati ambang klien | **klien** | saat itu juga, bukan di post-mortem |

## 5. Setelah insiden

1. **Satu kalimat di changelog rilis** (wajib, Doc 10 §11 butir 10) — pilih salah satu bentuk persisnya:
   - "aman, tinggal deploy ulang kode", **atau**
   - "perlu `migrate:rollback --step=N` (N dari `migrate:status`) karena …", **atau**
   - "restore `pre-<release>.dump`".
2. Angka yang dicatat: waktu deteksi→down, waktu down, versi yang dimundurkan, N (kalau ada), jumlah transaksi terpengaruh, nominal rupiah yang harus direkonsiliasi, waktu pemulihan nyata (masukkan ke register `backup-restore-drill.md` §5).
3. Temuan proses yang harus ditutup dengan tiket (bukan dengan tekad):
   - kalau contract/destruktif jalan satu rilis dengan expand ⇒ **pelanggaran M-9** — perbaiki pipeline, tambahkan pemeriksaan;
   - kalau `down()` gagal padahal C5 seharusnya menolak ⇒ isu CI C5/M-6;
   - kalau `pre-<release>.dump` tidak ada ⇒ isu prosedur deploy Doc 10 §9 butir 1 (murni, tidak bisa dinegosiasi);
   - kalau `docs/schema.snapshot.sql` basi ⇒ isu C6 (Doc 10 §10);
   - kalau flag tidak bisa memisahkan fitur rusak ⇒ isu feature flag (Blueprint §4 System).
4. Uji ulang sebelum rilis berikutnya: **latihan rollback** di staging (mundurkan tag + smoke) — sesuai DoD Blueprint §19 "rollback diuji". Ini berbeda dari drill restore (Doc 03 §8) dan keduanya harus punya bukti sendiri.
5. Perbarui `docs/threat-model.md` T-27 (rantai pasok/registry) bila akar masalahnya dependency, dan T-33 (satu node) bila downtime memperlihatkan margin kapasitas yang tidak ada. Butir ADR yang biasanya tersentuh: **ADR-010** (monorepo CI/CD + independent deploys + strategi migrasi; Doc 10 §1 M-1…M-4 adalah lampirannya).
