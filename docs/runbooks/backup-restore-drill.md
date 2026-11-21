# Runbook — Verifikasi backup terjadwal & drill restore triwulanan

**Severity awal:** SEV-2 (backup gagal) → SEV-1 (backup tidak bisa dibaca, atau restore terakhir > RPO)
**Aturan pokok: backup yang belum diuji bukan backup.** Blueprint §13 minta "recovery drills, RPO/RTO terukur"; Doc 10 §9 menulis latihan restore wajib satu kali di Sprint 0 dan satu kali sebelum launch "dan hasilnya didokumentasikan **sebagai bukti, bukan niat**"; Doc 03 §8 memasukkannya ke hardening. Runbook ini adalah cara membuktikan kalimat itu setiap triwulan.
**Rujukan:** Doc 10 §9 (backup/rollback/pemulihan), §5.4 (freeze window), §10 C6 (drift check vs `docs/schema.snapshot.sql`), M-13 · Blueprint §13 (PITR/WAL, object backup, recovery drills, RPO/RTO), §11 (backup & restore tests, monitoring disk), §14.1 (single node = SPOF), §15 (runbook wajib "database failover"), §19 (DoD: backup/restore diuji) · Doc 11 **B-12**, **B-15**, §2 (aturan M-1…M-13 tidak diubah) · Doc 01 §11 (job backup scheduler → tujuan off-node; Doc 11 §8.7) · `docs/threat-model.md` T-28, T-33

> **Mekanisme backup (P0.18):** image `docker/postgres/Dockerfile` (Postgres 16 alpine + WAL-G dipin) mengarsip setiap segmen WAL lewat `archive_command` (`wal-archive.sh`, gagal tertutup bila tujuan/kunci kosong) dan service `backup` (profil compose `backup`) membuat base backup harian + retensi `WALG_RETAIN_FULL`. Tujuan = bucket S3-compatible **di provider lain** dengan kredensial `BACKUP_S3_*` khusus backup (bukan MinIO aplikasi, Doc 11 §8.6); isi dienkripsi libsodium dengan `WALG_LIBSODIUM_KEY` sebelum keluar node, dan kunci itu wajib disimpan juga di luar node.
>
> **Mekanisme backup objek (P0.18):** service `object-backup` (profil `backup`, image `rclone/rclone` dipin, skrip `docker/object-backup/object-backup.sh`) menyinkronkan lima bucket — `product-media`, `request-attachments`, `invoice-documents`, `shipment-documents`, `audit-archives` — setiap `OBJECT_BACKUP_INTERVAL_SECONDS` ke provider `BACKUP_S3_*` yang sama di bawah `OBJECT_BACKUP_S3_PATH`. `exports` (TTL 7 hari, bisa dibuat ulang) dan `incoming/` (staging belum tervalidasi) tidak ikut. Sumber dibaca dengan service account baca-saja `ELZARA_BACKUP_READER_*` (policy `elzara-backup-reader.json`, dibuat `init-buckets.sh`), tidak pernah root/aplikasi. Isi **dan nama** objek dienkripsi rclone crypt (`OBJECT_BACKUP_CRYPT_PASSWORD` + `_SALT`, simpan di luar node seperti kunci WAL-G). Layout tujuan: `current/<bucket>/` = cermin terakhir; objek yang dihapus/ditimpa di sumber dipindah ke `deleted/<stamp UTC>/<bucket>/` dan dipangkas setelah `OBJECT_BACKUP_RETAIN_DAYS`. Hanya versi terkini yang disalin; riwayat versi MinIO tidak dipulihkan. Baris `media` ikut backup Postgres; setelah restore, `content:media:relink-versions` menautkan ulang `media.object_version` ke VersionId baru bila sha256 cocok.
> Drill PITR terisolasi: `scripts/backup-pitr-drill.sh` (hasil di register §5). Perintah yang masih bertanda **`# belum diverifikasi di lingkungan ini`** belum dijalankan terhadap staging Dokploy.

---

## 1. Deteksi (verifikasi terjadwal, bukan saat butuh)

| Sinyal | Sumber | Ambang **(sementara)** |
|---|---|---|
| job backup terakhir sukses? umur backup tertua yang masih tahan-lengkap? | log scheduler + listing bucket `backups` | backup > **26 jam** ⇒ SEV-1 (RPO harian); > **2× interval** ⇒ SEV-2 |
| dump bisa **dibaca** | `pg_restore --list` atas file yang baru dibuat (Doc 10 §9 langkah 1: "verifikasi bisa dibaca") | gagal ⇒ SEV-1, dump dianggap tidak ada |
| `pre-<release>.dump` ada untuk setiap rilis yang membawa migrasi | changelog rilis + bucket | tidak ada ⇒ rilis berikutnya ditahan (Doc 10 §9) |
| object backup (bucket + `media`) ikut tersalin | healthcheck service `object-backup` (unhealthy bila sync sukses terakhir > 3× interval) + log `object_backup.succeeded`/`object_backup.failed` (Blueprint §13, §11) | unhealthy / tidak sukses 7 hari ⇒ SEV-2 |
| kapasitas disk volume `pgdata`/minio/backup | monitoring disk (Blueprint §11) | > 80% ⇒ SEV-2; > 90% ⇒ SEV-1 (satu node, B-15) |
| WAL/PITR archive berjalan | alert `ElzaraWalArchiveFailing` (`elzara_pg_wal_archive_failures_total`, `elzara_pg_wal_last_archived_age_seconds`, `infrastructure/monitoring/prometheus/alerts.yml`) | gagal atau arsip terakhir > 15 menit ⇒ SEV-2 (WAL menumpuk di disk lokal, RPO memburuk) |
| base backup harian sukses | healthcheck service `backup` (unhealthy bila base backup terakhir > 26 jam) + log JSON `backup.succeeded`/`backup.failed` | unhealthy ⇒ SEV-2 |
| hasil **drill** terakhir lebih tua dari 1 triwulan | register drill §5 | > 100 hari ⇒ SEV-2 (bukan SEV-1, tapi tidak boleh dibiarkan) |

## 2. Dampak

Tanpa backup yang terbukti, satu kegagalan disk di **satu-satunya node** (Doc 11 B-15) berarti kehilangan A-1 (PII), A-3 (keadaan uang), A-4 (kebenaran stok), A-5 (kuotasi), A-7 (bukti pembelian) sekaligus. Tidak ada failover otomatis yang akan menyelamatkan kita — pemulihan di topologi ini **adalah** restore manual. Konsekuensi hukum/kepatuhan ikut di dalamnya: Blueprint §13 mengikat kita pada retensi & hak data sesuai regulasi Indonesia; kita tidak bisa menepati permintaan hapus/akses kalau tidak tahu isi salinan mana yang masih hidup (lihat `data-breach.md` §3.7).

**Target (diisi oleh hasil drill, bukan oleh keinginan):** RTO = ___ , RPO = ___ . Angka sementara untuk memulai: RTO 4 jam, RPO 24 jam — dan keduanya wajib dikoreksi dengan waktu nyata yang terukur di §3.4. Drill lokal pertama (§5) membuktikan mekanismenya (RPO dibatasi `archive_timeout` = 5 menit, RTO 3 detik untuk database ~30 tabel nyaris kosong); angka itu **bukan** angka produksi dan target di atas baru diganti setelah drill di staging Dokploy dengan volume data nyata.

## 3. Langkah tanggap

### 3.1 Verifikasi harian (murah, otomatis, boleh dijam)

```bash
# 1) daftar base backup off-node (jalur akses = key service backup saja; T-28)
docker compose -f docker/compose.yaml -f docker/profiles/production.yaml --profile backup exec backup wal-g backup-list --detail   # belum diverifikasi di lingkungan ini
# 2) uji BACA, bukan uji ADA — langkah wajib Doc 10 §9 butir 1: rantai WAL utuh sejak base backup tertua
docker compose -f docker/compose.yaml -f docker/profiles/production.yaml --profile backup exec backup wal-g wal-verify integrity timeline   # belum diverifikasi di lingkungan ini
# 3) titik migrasi dicatat (Doc 10 §9 butir 2)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT max(id) AS max_id, max(migration) AS last_migration, count(*) AS n FROM migrations;"   # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` `pg_restore --list` mencetak daftar objek (bukan error "unsupported version / not a tar archive"); `last_migration` cocok dengan yang tercatat di changelog rilis; salinan catatan masuk log backup. Kalau salah satu gagal → **anggap tidak ada backup** dan lanjut §3.2.

### 3.2 Bila backup hari ini tidak valid

1. Jangan menghapus salinan lama: kita mungkin harus mundur lebih jauh dari yang direncanakan.
2. Buat dump manual segera, dan verifikasi dengan `pg_restore --list` (langkah yang sama, sekarang, interaktif).
3. Cek apa yang ikut tidak tersalin: WAL archive (`Blueprint §13`), bucket objects, `media`.
4. Naikkan status: bila dump manual pun gagal ⇒ **SEV-1**, mulai §4 dan pertimbangkan penahanan rilis (Doc 10 §5.4 freeze window).

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec -T postgres pg_dump -Fc -f - "$POSTGRES_DB" > /var/backups/elzara/manual-$(date -u +%Y%m%dT%H%M%Z).dump   # belum diverifikasi di lingkungan ini — nama DB/user & path tujuan perlu dipastikan
```

`[ verifikasi ]` ukuran file > 0 dan sebanding dengan dump harian terakhir (selisih > 3× = sinyal ada yang salah isi, bukan cuma salah nama).

### 3.3 **Drill restore triwulanan** — syarat mutlak

Aturan keras:
- **Jangan pernah** restore ke database produksi. Drill dijalankan di **staging / scratch container** dengan salinan dump, memakai kredensial yang **bukan** kredensial produksi (README §2.1).
- **Jangan** menyisipkan langkah "ambil kredensial prod dari env produksi" — kalau drill membutuhkan itu, drill-nya yang salah desain (threat-model T-28, T-26).
- Waktu dihitung **dari nol** oleh orang yang tidak membuat backup itu.

### 3.4 Langkah bertimed (isi tabel durasi saat drill)

| # | Langkah | Perintah (semua **belum diverifikasi**) | Verifikasi | Target |
|---|---|---|---|---|
| 1 | Ambil dump terverifikasi terbaru dari bucket ke host drill | `docker compose ... exec minio mc cp local/backups/<file> /tmp/restore/` | `sha256sum` dicatat & cocok dengan manifest | 0:00–0:10 |
| 2 | Sediakan DB scratch Postgres 16 **terisolasi** | `docker compose -f docker/compose.yaml run --rm --name scratch-pg postgres:16` (jaringan terpisah, port tidak dipublikasikan) | `psql -c "select version();"` = Postgres 16.x | 0:10–0:20 |
| 3 | Restore schema+data | `docker compose ... exec -T scratch-pg pg_restore -U <user> -d <db> --no-owner --exit-on-error /tmp/restore/<file>.dump` | exit code 0; `--exit-on-error` **jangan** dilepas untuk "meringankan" | 0:20–0:45 |
| 4 | Cocokkan titik migrasi | `psql -c "SELECT max(id), max(migration) FROM migrations;"` | sama persis dengan catatan §3.1 butir 3 | — |
| 5 | Drift check skema (Doc 10 §10/C6) | bandingkan `information_schema` + constraint + index hasil restore vs `docs/schema.snapshot.sql` | **tidak ada beda**; kalau beda: dump itu bukan dari rilis yang benar → ulangi dari langkah 1 dengan file yang benar | — |
| 6 | Invariant kebenaran | query dari `oversell.md` §3.2 (agregat vs ledger, agregat vs reservasi) dan `stock-discrepancy.md` §3.2 | 0 baris selisih | — |
| 7 | Hitung baris kunci vs sumber | `SELECT relname, n_live_tup FROM pg_stat_user_tables ORDER BY n_live_tup DESC LIMIT 30;` | sebanding dengan produksi (selisih wajar ≤ 1 siklus backup) | — |
| 8 | Hidupkan aplikasi pada dump itu | `docker compose ... run --rm app php artisan migrate --force` (harus "Nothing to migrate") lalu smoke: login, cari, checkout ke driver `manual`, satu transisi order (Doc 10 §9 langkah 4) | smoke hijau; `migrate --force` tidak menjalankan apa pun | 0:45–1:15 |
| 9 | Objek & media | provisioning MinIO drill dengan `init-buckets.sh`, lalu `docker compose --profile backup run --rm -e RESTORE_S3_ENDPOINT=<minio drill> -e RESTORE_S3_ACCESS_KEY=… -e RESTORE_S3_SECRET_KEY=… object-backup restore`; `php artisan content:media:relink-versions` di container api; buka 1 objek `product-media` + 1 `request-attachments` lewat signed URL. Objek yang terhapus: `object-backup list deleted` lalu salin dari `deleted/<stamp>/` | restore mencetak `objects>0` (kunci salah ⇒ 0 dan exit ≠ 0); relink `missing=0 mismatched=0 failed=0`; 200/OK dengan konten benar; bucket privat **tidak** bisa dibaca tanpa tanda tangan | — |
| 10 | Ukur & tutup | hentikan stopwakt; tulis angka nyata ke register §5 | RTO/RPO terukur = isi baru §2 | 1:15– |

`[ verifikasi ]` **drill hanya dinyatakan lulus bila** ada: (a) smoke test aplikasi hijau di atas dump itu, (b) drift check C6 bersih, (c) invariant bersih, (d) dua angka nyata (RTO, dan umur data yang hilang = RPO efektif). "Restore selesai" tanpa (a)–(d) = gagal.

### 3.5 PITR / titik-waktu (kalau recovery butuh mundur ke menit tertentu)

Blueprint §13 menyebut PITR/WAL. Prosedur: `wal-g backup-fetch <PGDATA baru> LATEST` → `recovery.signal` + `restore_command = 'wal-g wal-fetch %f %p'` + `recovery_target_time` + `recovery_target_action = 'promote'` → start Postgres di volume/node baru. Retensi: `WALG_RETAIN_FULL` (default 7) base backup penuh beserta WAL di antaranya; WAL lebih tua dihapus oleh `wal-g delete retain`. **Status:** terbukti di drill lokal terisolasi (§5, `scripts/backup-pitr-drill.sh`); **belum** terbukti dari tujuan off-node staging Dokploy karena host/bucket staging belum ada.

`[ verifikasi ]` sebelum PITR dijanjikan ke klien: (a) arsip WAL benar-benar sampai ke luar VPS, (b) satu restore-per-titik-waktu pernah berhasil di drill, (c) RPO yang dijanjikan = jarak arsip WAL teraman yang terbukti, bukan "±5 menit" karangan. (b) terbukti di drill lokal; (a) dan (c) menunggu drill staging. Sampai itu terbukti, janji ke klien tetap RPO 24 jam.

### 3.6 Pemulihan darurat nyata (bukan drill)

Urutan saat produksi benar-benar hilang/rusak:

1. Nyatakan **SEV-1** + status page + hentikan deploy (Doc 10 §5.4). Jangan `migrate` apa pun sampai titik pemulihan diputuskan.
2. Pilih dump: `pre-<release>.dump` untuk kembali ke **pra-rilis** (Doc 10 §9: "Kalau datanya sudah bersentuhan, jangan mundur: restore dump pra-rilis"), atau dump terjadwal terakhir untuk kembali ke operasi terakhir. **Perbedaan konsekuensinya transaksi customer** — putuskan dengan `finance` + klien, catat siapa memutuskan.
3. Sediakan node/host baru (atau volume baru) — kita tidak punya failover (B-15).
4. Jalankan langkah §3.4 nomor 2–8 terhadap environment **produksi** dengan kredensial produksi, setelah `php artisan down`.
5. Rekonsiliasi pasca-restore **wajib**: `payment_ledger` vs settlement report provider (U5), order yang dibayar di jendela hilang, stok, dan notifikasi yang tidak terkirim (outbox ikut ter-backup → efek bisa dobel bila dikirim ulang; jalankan `webhook-replay.md` §3.3).
6. Baru lepas `php artisan up` setelah smoke + rekonsiliasi hijau.

`[ verifikasi ]` daftar eksplisit "apa yang hilang di antara" (jumlah order, rentang waktu, nominal rupiah) dikirim ke klien **sebelum** layanan dibuka kembali; tidak ada auto-adjust (U5).

### 3.7 Backup yang bocor / hilang (T-28)

Kalau salinan backup diketahui berpindah tangan atau hilang: perlakukan sebagai **pelanggaran data** (A-1/A-3/A-5 ikut tersalin) dan jalankan `data-breach.md`. Ingat: rotasi kredensial **tidak** membuat dump lama tidak berbahaya — dump punya umur sendiri. Yang wajib: matikan akses bucket, ganti key, putuskan retensi & penghancuran salinan, dan catat dump mana yang tidak bisa lagi ditarik.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| tidak ada dump yang bisa dibaca untuk 24 jam terakhir | penanggung jawab rilis + **klien** (SEV-1) | segera; tunda rilis apa pun |
| drill triwulan molor > 2 minggu | penanggung jawab rilis + klien | minggu itu juga |
| perlu menambah disk/volume/offsite storage | pemilik infra VPS (**klien**) | sebelum write error |
| RTO terukur lebih buruk dari janji ke klien | **klien** (komitmen layanan) | segera setelah terukur, bukan setelah dijanjikan |
| bucket backup hanya bisa diakses dengan key yang sama dengan aplikasi | `superadmin` (pemisahan akses — Blueprint §11 "separate access keys per service") | ≤ 1 minggu, dengan tiket |

## 5. Setelah insiden / setelah drill

1. **Register drill** (isi setiap triwulan; ini artefak yang diminta Doc 10 §9 & Doc 03 §8): tanggal, siapa yang mengukur, file dump (nama + ukuran + sha256), waktu per langkah §3.4, RTO terukur, RPO efektif, hasil smoke/C6/invariant, dan apa yang gagal.
2. Perbarui angka target di §2 dengan yang terukur. **Dokumen ini tidak boleh berisi target yang tidak pernah diuji.**
3. Bila drill gagal: buka tiket dengan kategori "recovery gap", dan jalankan §3.6 sebagai prosedur darurat yang berlaku sampai gap tertutup. Putuskan juga apakah freeze rilis (Doc 10 §5.4) perlu diperluas.
   | Tanggal | Lingkungan | Pengukur | Hasil |
   |---|---|---|---|
   | 2026-11-21 | lokal terisolasi (`scripts/backup-pitr-drill.sh`: primary image produksi + migrasi Laravel asli → MinIO terpisah sebagai stand-in off-node → restore PITR ke cluster scratch) | Devin (P0.18) | PASS — rincian di bawah |
   | 2026-11-22 | lokal terisolasi (`scripts/backup-object-drill.sh`: MinIO sumber via `init-buckets.sh` → `object-backup.sh` ke MinIO terpisah sebagai stand-in off-node → restore ke MinIO baru) | Devin (P0.18) | PASS — rincian di bawah |

   Hasil drill 2026-11-21 (keluaran skrip apa adanya):

   | Item | Hasil |
   |---|---|
   | Status | PASS |
   | Postgres / WAL-G | 16.16 / v3.0.10 |
   | Tujuan | MinIO terpisah, kredensial khusus backup, enkripsi libsodium + brotli |
   | Objek off-node | 12 (berkas memuat teks tabel: 0) |
   | Kunci salah | ditolak |
   | Durasi base backup | 1s |
   | Arsip WAL | 4 archived, 0 failed |
   | Target PITR | 2026-11-21 21:56:10.825808+00 |
   | Baris terakhir pulih | 2026-11-21 21:56:09.762463+00 |
   | Isi tabel pulih | after-base=250 before-base=500 (diharapkan after-base=250 before-base=500; batch insiden & DELETE tidak ikut) |
   | Migrasi | primary 29:0084_Content_add_media_object_version → restore 29:0084_Content_add_media_object_version |
   | Tabel public pulih | 29 |
   | RTO terukur (fetch + replay WAL + promote) | 3s |

   Hasil drill objek 2026-11-22 (keluaran skrip apa adanya):

   | Item | Hasil |
   |---|---|
   | Status | PASS |
   | Tujuan | MinIO terpisah, kredensial khusus backup, rclone 1.71.2 crypt (isi + nama) |
   | Cakupan | 5 bucket; `incoming/` dan `exports` tidak tersalin |
   | Objek off-node | 5 (berkas/path memuat isi atau nama objek plaintext: 0) |
   | Objek terhapus di sumber | dipindah ke `deleted/<stamp>/`, dipangkas setelah retensi |
   | Kunci salah | restore gagal (exit ≠ 0), 0 objek tertulis |
   | Durasi backup / restore | 1s / 1s |
   | Objek pulih | 5, sha256 sama dengan sumber (versi terkini) |
   | Akses pasca-restore | `product-media/published` anonim 200; privat tanpa tanda tangan 403; privat signed URL 200 |

   Relink `media.object_version` diuji di `tests/Feature/Content/MediaBackupTest.php` (MinIO nyata di CI), bukan di drill ini.

   Belum tercakup drill ini dan wajib di drill staging: tujuan off-node sungguhan di provider lain, volume data nyata (RTO 3 detik di sini hanya mengukur mekanisme), smoke aplikasi + drift C6 + invariant §3.4 butir 5–8, dan restore objek/media dari bucket off-node staging bersama restore Postgres yang sama (§3.4 butir 9, termasuk relink).
4. Tiket/ADR & isu yang biasanya lahir dari drill:
   - enkripsi at-rest + **pemegang kunci** terpisah dari VPS (T-28);
   - retensi & penghancuran salinan (Blueprint §13; kebutuhan hak hapus — `data-breach.md` §3.7);
   - pemisahan key service backup dari key aplikasi (Blueprint §11);
   - WAL archiving + PITR yang benar-benar teruji (§3.5);
   - monitoring kapasitas disk untuk volume backup (Blueprint §11);
   - snapshot `docs/schema.snapshot.sql` basi (Doc 10 §10/C6).
5. Perbarui `docs/threat-model.md` T-28/T-33 (kolom kontrol & sisa risiko) dan `docs/runbooks/rollback-release.md` §3 bila restore ternyata bukan opsi yang aman untuk rilis berikutnya.
