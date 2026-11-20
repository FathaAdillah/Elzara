# Runbook — Antrean Horizon tersangkut / backlog / DLQ

**Severity awal:** SEV-2 → SEV-1 bila antrian `payments` atau `inventory` ikut tersendat (skenario Blueprint §15 "stuck job/DLQ"; aset A-10; ancaman T-37, T-20)
**Rujukan:** Blueprint §9 (worker pool terpisah + aturan reliability + "Prioritaskan payment callbacks dan inventory expiry; report exports tidak boleh menghambat checkout"), §15 (metrik queue lag), §8.2 (outbox/inbox) · Doc 11 **B-7**, **B-12**, **B-15**, **§8.1** (tujuh nama antrian kanonik) · Doc 01 §11.1 (`ulimits`/`deploy.resources.limits` untuk worker, Horizon paling cepat menghabiskan RAM VPS), §10 (retry exponential backoff + jitter **dengan batas percobaan** → DLQ setelah batas + alert), §6.3 (batas retry konflik transaksi = **3** percobaan: 1 awal + 2 ulang), §12 (metrik queue lag & DLQ) · Doc 11 §2 aturan **B4** (outbox ditulis dalam transaksi yang sama; dispatcher membaca setelah commit) · `docs/threat-model.md` T-37/T-20/T-14

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1). Nama antrian mengikuti **Doc 11 §8.1** (= Blueprint §9): tujuh dan hanya tujuh — `payments` · `orders` · `inventory` · `notifications` · `imports` · `reports` · `media`. **Doc 01 §10 sudah memakai tujuh nama ini**, jadi tidak ada lagi perbedaan daftar; yang masih harus dicocokkan saat dijalankan adalah isi `horizon.conf` produksi (jumlah supervisor per pool) — itu artefak Sprint 0, belum ada di repo. Daftar lama (`default`, `search`, `reconcile`) **tidak berlaku** (Doc 11 §8.1).

---

## 1. Deteksi

| Sinyal | Sumber | Ambang **(sementara — Q-4)** |
|---|---|---|
| **queue lag** per antrian | metrik Blueprint §15; dashboard Horizon | `payments` > 60 dtk · `inventory` > 120 dtk · `orders` > 300 dtk · `reports`/`imports` > 30 menit ⇒ SEV-2 |
| kedalaman antrian (jumlah job menunggu) | Horizon dashboard / metrik | > 3× rata-rata 1 jam terakhir ⇒ SEV-2; antrian `payments` > 500 ⇒ SEV-1 |
| `failed_jobs` bertambah | DLQ (Blueprint §9; Doc 01 §10 "DLQ setelah batas", §12 "jumlah job di DLQ") | > 0 untuk job uang/stok ⇒ SEV-1 |
| `outbox_events` belum ter-dispatch | query §3.5 (`dispatched_at IS NULL`) | > 200 baris **atau** baris tertua > 5 menit ⇒ SEV-1 (efek samping di luar proses tertahan: notifikasi, webhook, search, shipment sync) |
| worker OOM / restart, supervisor hilang | `docker compose ps`, log, memori container | restart > 2×/jam ⇒ SEV-1 (Doc 01 §11.1) |
| Redis memory / `appendonly` tumbuh | metrik Blueprint §15 | mendekati limit ⇒ SEV-2 (job hilang saat Redis ditendak eviction — cache dan queue berbagi instance Redis yang sama, Doc 01 §11.1: `redis:7` untuk cache/queue/lock) |
| error rate 5xx naik + p95 checkout naik | metrik Blueprint §15 | lihat `cloudflare-waiting-room.md` §3.5 (admission mungkin penyebabnya, bukan worker) |

## 2. Dampak

Backlog di `payments` = uang tercatat terlambat → order kedaluwarsa → gelombang `paid_stock_exception` (lihat `payment-callback-late.md` §3.6). Backlog di `inventory` = reservasi kedaluwarsa tidak dilepas → stok "terkunci hantu" → buyer tidak bisa beli padahal barang ada; sekaligus membuat reservation expiry menumpuk (metrik Blueprint §15). Outbox tersendat = efek yang **sudah commit** tidak pernah keluar: buyer tidak dapat invoice/resi, status tidak disinkron, index Elasticsearch basi — dan karena `no side effects before DB commit` (Blueprint §9), job yang keluar nanti **tidak** menghasilkan state baru yang salah, hanya terlambat.

Satu node (B-15): worker, app, scheduler, Postgres, Redis, MinIO berebut RAM yang sama. Menaikkan jumlah worker tanpa batas adalah cara tercepat menjatuhkan seluruh platform.

## 3. Langkah tanggap

### 3.1 Kenali bentuk kemacetan dulu (3 menit)

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml ps                              # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:status        # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:supervisors   # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'baca ukuran antrian per nama queue';"                                                   # belum diverifikasi — perintah pengukuran lag per queue masih rencana
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec redis redis-cli INFO memory | grep -E "used_memory_human|maxmemory_human|evicted_keys"   # belum diverifikasi
```

Pilih cabang:

| Bentuk | Ciri | Cabang |
|---|---|---|
| **B1 — worker mati/pause** | supervisor 0, `horizon:status` bukan `Running` | §3.2 |
| **B2 — worker hidup tapi lambat** | ada progres, lag tetap naik | §3.3 (prioritas) |
| **B3 — job gagal berulang (DLQ)** | progres 0, `failed_jobs` naik, `last_error` sama | §3.4 |
| **B4 — outbox tidak ter-dispatch** | antrian pendek tapi `dispatched_at IS NULL` menumpuk | §3.5 |
| **B5 — Redis kehabisan memori / key ter-evict** | `evicted_keys` naik | §3.6 |

### 3.2 B1 — nyalakan kembali dengan benar

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:continue     # belum diverifikasi di lingkungan ini
# bila container hilang/restart loop:
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml up -d worker                                 # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --tail=200 worker | grep -iE "OOM|killed|shutdown|graceful"   # belum diverifikasi
```

`[ verifikasi ]` `horizon:status` = Running, supervisor per antrian terlihat, lag mulai turun dalam 5 menit.
Catatan: **graceful shutdown** diminta Blueprint §9 — kalau log memperlihatkan job dipotong di tengah (`SIGKILL`/timeout), itu bukan sekadar lambat: kemungkinan besar ada efek ganda yang harus dicek lewat dedup (§3.4) dan `webhook-replay.md`.

### 3.3 B2 — beri prioritas: yang jalan duluan, yang di-pause

Urutan prioritas (Blueprint §9 kalimat eksplisit: dahulukan **payment callbacks** dan **inventory expiry**; ekspor laporan **tidak boleh** menghambat checkout):

**Jalan terus / dinaikkan:** `payments` → `inventory` → `orders` → `media` (hanya untuk finalize upload yang menunggu) → `notifications` (boleh tertunda, jangan berhenti total).
**Pause dulu:** `reports`, `imports` — keduanya pekerjaan berat dan tidak memegang uang/stok (Blueprint §9). Reindex Elasticsearch **tidak** punya antrian sendiri (tujuh nama tetap tujuh — Doc 11 §8.1): scheduler menjadwalkan pekerjaan dan worker melakukan akses ke indeks melalui network `search` (Doc 01 §11.1), jadi kalau reindex yang membebani DB, yang ditahan adalah siklus job reindex di scheduler (§3.3 blok perintah terakhir), bukan sebuah antrian `search`.

```bash
# pause pekerjaan yang boleh tertunda
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:pause --queue=reports    # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:pause --queue=imports    # belum diverifikasi di lingkungan ini

# tambah kapasitas HANYA untuk antrian uang/stok, dengan batas RAM node sebagai pagar
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:scale payments 2        # belum diverifikasi di lingkungan ini
# bila scheduler (cron) ikut membebani: tahan satu siklus job berat, jangan matikan scheduler keseluruhan
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec scheduler php artisan schedule:work --help         # belum diverifikasi — bentuk penjadwalan per job belum ada
```

`[ verifikasi ]` lag `payments` dan `inventory` turun dalam 10 menit; `reports`/`imports` tidak menambah beban; **memori container worker tidak naik melewati `deploy.resources.limits`** (Doc 01 §11.1). Kalau naik, kurangi lagi — jangan lawan OOM dengan replica di satu node.

Larangan: jangan `horizon:clear` untuk antrian `payments`/`inventory`/`orders` "biar cepat". Itu membuang pekerjaan, dan efek sampingnya (invoice, stok, notifikasi) tidak pernah jalan. `horizon:clear` hanya untuk `reports`/`imports` yang memang idempoten dan bisa dijalankan ulang dari awal:

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan horizon:clear reports   # belum diverifikasi di lingkungan ini — PASTIKAN job-nya bisa diulang aman
```

`[ verifikasi ]` pekerjaan itu bisa di-trigger ulang oleh admin (layar Reports/Imports) tanpa menghasilkan dobel; catatan dibekukan di log insiden.

### 3.4 B3 — DLQ: baca dulu, retry kemudian

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'daftar failed_jobs per queue + exception';"            # belum diverifikasi — query langsung lebih aman:
```

```sql
SELECT queue, COUNT(*) AS n, MAX(failed_at) AS terakhir,
       LEFT(exception, 200) AS potongan_error
FROM failed_jobs
GROUP BY queue, LEFT(exception, 200)
ORDER BY n DESC;
```

`[ verifikasi ]` penyebab terkelompok jadi satu dari: (a) provider/eksternal timeout → circuit breaker (Blueprint §9); (b) constraint DB (unique/CHECK) → ini **bukan** untuk di-retry buta, tapi periksa `webhook-replay.md` atau `oversell.md`; (c) bug kode → jangan retry, perbaiki; (d) deadlock → retry ber-backoff sudah seharusnya menanganinya sendiri (Blueprint §8.1); kalau sampai DLQ, urutan lock perlu diaudit.

```bash
# retry satu job setelah penyebab eksternal pulih (handler idempoten — Blueprint §9)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan queue:retry <failed-job-id>   # belum diverifikasi di lingkungan ini
# atau semua job dari satu antrian, HANYA kalau penyebabnya sudah pasti eksternal
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan queue:retry --queue=notifications all   # belum diverifikasi
```

`[ verifikasi ]` tidak ada efek ganda: tidak ada notifikasi dobel (`dedup (event, channel, recipient)` — threat-model T-37), tidak ada capture ganda (query `HAVING COUNT(*) > 1` di `payment-callback-late.md` §3.4), tidak ada GRN/paket ganda.

### 3.5 B4 — menguras transactional outbox

Dispatcher outbox adalah satu-satunya jalan efek keluar proses (B4 + Blueprint §8.2). Kalau ia macet, DB benar tapi dunia luar tidak.

```sql
-- tertinggal berapa, dan siapa yang memegangnya
SELECT COUNT(*) AS tertunda, MIN(occurred_at) AS tertua
FROM outbox_events
WHERE dispatched_at IS NULL;

SELECT id, aggregate_type, occurred_at, attempts, last_error, locked_by, locked_at
FROM outbox_events
WHERE dispatched_at IS NULL
ORDER BY occurred_at
LIMIT 20;

-- lock dispatcher yang tertinggal dari worker yang mati (stale)
SELECT id, locked_by, locked_at
FROM outbox_events
WHERE dispatched_at IS NULL AND locked_at < NOW() - INTERVAL '15 minutes';
```

`[ verifikasi ]` jumlah tertunda dan umur tertua tercatat **sebelum** tindakan apa pun. Stale lock = gejala worker dispatcher mati di tengah jalan (B1), bukan izin untuk menghapus baris.

```bash
# jalankan dispatcher satu siklus di depan antrian lain
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec worker php artisan queue:work \
  --queue=payments --tries=1 --stop-when-empty                  # belum diverifikasi di lingkungan ini — nama job dispatcher BELUM ADA (belum ada kode); `payments` dipakai di sini karena dispatcher efek uang adalah yang paling darurat, **bukan** karena Doc 01 §10 menetapkan pool dispatcher (DOC 01 §10 tidak memilih pool untuk dispatcher outbox — butir terbuka README §5 nomor 13)
```

Aturan tegas:
- **Jangan** menghapus/mengedit baris `outbox_events` (append-only, S-6 + Doc 10 §5.6). Koreksi = baris baru dengan `reverses_id`.
- **Jangan** menandai `dispatched_at` manual supaya "antreannya bersih". Efeknya akan hilang selamanya (notifikasi/resi/index tidak pernah terkirim) — dan kita akan mengira sudah terkirim.
- Kalau satu baris gagal terus karena konsumen tujuan rusak: perbaiki konsumennya (atau matikan flag sumbernya), bukan memaksa dispatch.

### 3.6 B5 — Redis penuh / key ter-evict

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec redis redis-cli CLIENT INFO | grep -E "evicted_keys|maxmemory_policy"   # belum diverifikasi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec redis redis-cli --scan --pattern 'horizon*' | head -20                   # belum diverifikasi — hanya READ, jangan DEL apa pun
```

`[ verifikasi ]` `maxmemory_policy` diketahui; kalau `allkeys-lru`, job di antrian **bisa** terbuang — itu kondisi darurat dan harus dilaporkan sebagai SEV-1. Tindakan yang sah: kurangi volume dengan pause `reports`/`imports` (§3.3), naikkan batas memori sesuai kapasitas node, atau pindahkan queue ke instance Redis terpisah (keputusan infra — satu Redis untuk cache/queue/lock sesuai Doc 01 §11.1, dan sesi juga driver redis menurut Doc 09 §17). **Tidak sah**: `FLUSHALL`, atau `DEL` pada pola antrian tanpa tahu isinya.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| lag `payments` > 30 menit, atau `failed_jobs` berbau uang | penanggung jawab rilis (SEV-1) + peran `finance` | segera |
| outbox tertunda > 30 menit | penanggung jawab rilis; efek keluar (WA/email/resi) dianggap **tidak terkirim** oleh `customer-support` saat menjawab buyer | segera |
| butuh memori/Redis tambahan di node | **klien / pemilik infra** (VPS tunggal, B-15) | sebelum tindakan, bukan sesudah |
| ada job yang menyentuh provider (gateway/kurir/WA) dan gagal massal | peran + pemilik akun provider (klien — Q-6/Q-7, Doc 00 §Asumsi) | 1×24 jam dengan data: jumlah, percobaan, error |
| dugaan job dipaksa dispatch / jejak diubah | `superadmin` + `data-breach.md` | segera |

## 5. Setelah insiden

1. Bekukan angka: lag puncak per antrian, umur outbox tertua, jumlah `failed_jobs` dan per penyebab, berapa yang di-retry, berapa yang sengaja dibuang (harus 0 untuk antrian uang/stok).
2. Bukti test yang diminta dan masih terbuka (Blueprint §16): "Queue worker crash after side effect ⇒ Retry aman; no double charge/notification where dedup applies" — tambahkan fault injection (Blueprint §16 tools) kalau belum ada.
3. Tiket/ADR & isu:
   - kapasitas: angka dari load test untuk mengganti ambang §1 (**Q-4**);
   - kalau penyebabnya eksternal: nilai timeout/retry/circuit breaker per provider (Blueprint §9; Doc 01 §10 hanya menetapkan "retry exponential backoff + jitter **dengan batas percobaan**" tanpa menyebut angka — angka "maks 5" tidak terdokumentasi di mana pun, jadi jangan dikutip; simpan keputusannya di **ADR-006** untuk gateway).
   - kalau penyebabnya DB (deadlock): audit urutan lock SKU & bentuk reservasi (`reservation-expiry-race.md`, **ADR-005**);
   - kalau Redis dipakai untuk hal yang bukan cache/queue/lock: itu pelanggaran aturan "Redis bukan ledger" (Doc 11 §2), tulis isu terpisah.
4. Perbarui `docs/threat-model.md` T-37 (dedup efek ganda) dan T-20 (kehabisan sumber daya di satu node).
5. Kalau backlog sempat membuat checkout harus dimatikan: prosedur dan tingkat pembekuannya ada di `emergency-disable-checkout.md` §3.1–§3.2 (pasangan proseduralnya tetap tercatat di `cloudflare-waiting-room.md` §3.6), lalu jalankan lepas-pembekuan di `emergency-disable-checkout.md` §3.6 dan catat durasi nyata untuk bahan drill (`backup-restore-drill.md` §5).
