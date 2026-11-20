# Runbook — Verifikasi backup terjadwal & drill restore triwulanan

**Severity awal:** SEV-2 (backup gagal) → SEV-1 (backup tidak bisa dibaca, atau restore terakhir > RPO)
**Aturan pokok: backup yang belum diuji bukan backup.** Blueprint §13 minta "recovery drills, RPO/RTO terukur"; Doc 10 §9 menulis latihan restore wajib satu kali di Sprint 0 dan satu kali sebelum launch "dan hasilnya didokumentasikan **sebagai bukti, bukan niat**"; Doc 03 §8 memasukkannya ke hardening. Runbook ini adalah cara membuktikan kalimat itu setiap triwulan.
**Rujukan:** Doc 10 §9 (backup/rollback/pemulihan), §5.4 (freeze window), §10 C6 (drift check vs `docs/schema.snapshot.sql`), M-13 · Blueprint §13 (PITR/WAL, object backup, recovery drills, RPO/RTO), §11 (backup & restore tests, monitoring disk), §14.1 (single node = SPOF), §15 (runbook wajib "database failover"), §19 (DoD: backup/restore diuji) · Doc 11 **B-12**, **B-15**, §2 (aturan M-1…M-13 tidak diubah) · Doc 01 §11 (job backup scheduler → tujuan off-node; Doc 11 §8.7) · `docs/threat-model.md` T-28, T-33

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1). Angka target RTO/RPO di §2 bersifat sementara sampai drill pertama selesai.

---

## 1. Deteksi (verifikasi terjadwal, bukan saat butuh)

| Sinyal | Sumber | Ambang **(sementara)** |
|---|---|---|
| job backup terakhir sukses? umur backup tertua yang masih tahan-lengkap? | log scheduler + listing bucket `backups` | backup > **26 jam** ⇒ SEV-1 (RPO harian); > **2× interval** ⇒ SEV-2 |
| dump bisa **dibaca** | `pg_restore --list` atas file yang baru dibuat (Doc 10 §9 langkah 1: "verifikasi bisa dibaca") | gagal ⇒ SEV-1, dump dianggap tidak ada |
| `pre-<release>.dump` ada untuk setiap rilis yang membawa migrasi | changelog rilis + bucket | tidak ada ⇒ rilis berikutnya ditahan (Doc 10 §9) |
| object backup (bucket + `media`) ikut tersalin | job object backup (Blueprint §13, §11) | tidak berjalan 7 hari ⇒ SEV-2 |
| kapasitas disk volume `pgdata`/minio/backup | monitoring disk (Blueprint §11) | > 80% ⇒ SEV-2; > 90% ⇒ SEV-1 (satu node, B-15) |
| WAL/PITR archive berjalan | config WAL archiving (Blueprint §13) | menumpuk di disk lokal ⇒ SEV-2 (RTO memburuk, disk penuh) |
| hasil **drill** terakhir lebih tua dari 1 triwulan | register drill §5 | > 100 hari ⇒ SEV-2 (bukan SEV-1, tapi tidak boleh dibiarkan) |

## 2. Dampak

Tanpa backup yang terbukti, satu kegagalan disk di **satu-satunya node** (Doc 11 B-15) berarti kehilangan A-1 (PII), A-3 (keadaan uang), A-4 (kebenaran stok), A-5 (kuotasi), A-7 (bukti pembelian) sekaligus. Tidak ada failover otomatis yang akan menyelamatkan kita — pemulihan di topologi ini **adalah** restore manual. Konsekuensi hukum/kepatuhan ikut di dalamnya: Blueprint §13 mengikat kita pada retensi & hak data sesuai regulasi Indonesia; kita tidak bisa menepati permintaan hapus/akses kalau tidak tahu isi salinan mana yang masih hidup (lihat `data-breach.md` §3.7).

**Target (diisi oleh hasil drill, bukan oleh keinginan):** RTO = ___ , RPO = ___ . Angka sementara untuk memulai: RTO 4 jam, RPO 24 jam — dan keduanya wajib dikoreksi dengan waktu nyata yang terukur di §3.4.

## 3. Langkah tanggap

### 3.1 Verifikasi harian (murah, otomatis, boleh dijam)

```bash
# 1) daftar isi backup terbaru dari bucket (jalur akses = key service backup saja; T-28)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio mc ls -l --recursive local/backups/<tanggal-today>   # belum diverifikasi di lingkungan ini
# 2) uji BACA, bukan uji ADA — langkah wajib Doc 10 §9 butir 1
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec postgres pg_restore --list /tmp/<backup>.dump            # belum diverifikasi di lingkungan ini
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
| 9 | Objek & media | mount bucket salinan ke MinIO drill; buka 1 objek `product-media` + 1 `request-attachments` lewat signed URL | 200/OK dengan konten benar; bucket privat **tidak** bisa dibaca tanpa tanda tangan | — |
| 10 | Ukur & tutup | hentikan stopwakt; tulis angka nyata ke register §5 | RTO/RPO terukur = isi baru §2 | 1:15– |

`[ verifikasi ]` **drill hanya dinyatakan lulus bila** ada: (a) smoke test aplikasi hijau di atas dump itu, (b) drift check C6 bersih, (c) invariant bersih, (d) dua angka nyata (RTO, dan umur data yang hilang = RPO efektif). "Restore selesai" tanpa (a)–(d) = gagal.

### 3.5 PITR / titik-waktu (kalau recovery butuh mundur ke menit tertentu)

Blueprint §13 menyebut PITR/WAL. Prosedur dasarnya: restore base backup → replay WAL sampai `recovery_target_time`/`restore_command`. **Status hari ini: belum diverifikasi** — WAL archiving belum terbukti dikonfigurasi di Doc 01 §11 (yang terdokumentasi baru `redis:7 appendonly yes` + volume `pgdata` + job backup ke tujuan off-node), dan tidak ada dokumen yang menetapkan retention WAL.

`[ verifikasi ]` sebelum PITR dijanjikan ke klien: (a) arsip WAL benar-benar sampai ke luar VPS, (b) satu restore-per-titik-waktu pernah berhasil di drill, (c) RPO yang dijanjikan = jarak arsip WAL teraman yang terbukti, bukan "±5 menit" karangan. Sampai tiga hal itu terbukti, kapasitas pemulihan kita adalah **restore dump terakhir** = RPO 24 jam.

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
4. Tiket/ADR & isu yang biasanya lahir dari drill:
   - enkripsi at-rest + **pemegang kunci** terpisah dari VPS (T-28);
   - retensi & penghancuran salinan (Blueprint §13; kebutuhan hak hapus — `data-breach.md` §3.7);
   - pemisahan key service backup dari key aplikasi (Blueprint §11);
   - WAL archiving + PITR yang benar-benar teruji (§3.5);
   - monitoring kapasitas disk untuk volume backup (Blueprint §11);
   - snapshot `docs/schema.snapshot.sql` basi (Doc 10 §10/C6).
5. Perbarui `docs/threat-model.md` T-28/T-33 (kolom kontrol & sisa risiko) dan `docs/runbooks/rollback-release.md` §3 bila restore ternyata bukan opsi yang aman untuk rilis berikutnya.
