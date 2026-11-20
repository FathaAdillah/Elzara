# Runbook — MinIO / object storage tidak tercapai

**Severity awal:** SEV-2 → SEV-1 bila upload lampiran Request Order gagal massal atau bucket privat ikut terbuka (ancaman T-24, T-25, T-06; aset A-8)
**Rujukan:** Blueprint §11 (bucket & visibilitas, alur presigned PUT → finalize → validasi MIME/magic-byte → pemrosesan, "Presigned URL harus menunjuk endpoint yang benar-benar dapat diakses browser; internal Docker hostname tidak akan berfungsi di browser", CORS per origin, akses key terpisah per service, lifecycle, versioning, backup/restore test, monitoring kapasitas disk, konsol MinIO tidak diekspos publik), §16 (test "MinIO unavailable ⇒ upload gagal aman; checkout tetap berjalan jika tidak bergantung media"), §19 (DoD: presigned upload, validasi file, private access teruji), §15 (metrik `MinIO error rate`) · Doc 11 **B-9** · Doc 01 §11 + §9.2 (`minio` service; `FILESYSTEM_DISK=s3` + `AWS_*` endpoint MinIO) · Blueprint §18 butir 7 → **ADR-008** · `docs/threat-model.md` T-24/T-25/T-06/T-33

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1). Bucket yang dipakai = enam daftar Blueprint §11 / B-9, **bukan** nama lama (`media-public`/`media-private`). Dump backup **tidak** masuk keenamnya — lihat Doc 11 §8.7.

---

## 1. Deteksi

| Sinyal | Sumber | Ambang **(sementara)** |
|---|---|---|
| `MinIO error rate` | metrik Blueprint §15 | > 1% dari operasi storage 5 menit ⇒ SEV-2; 100% ⇒ SEV-1 |
| `POST /product-requests/{id}/attachments/presign` 5xx | HTTP 5xx + Sentry | ≥ 5/menit ⇒ SEV-1 untuk jalur Request Order |
| presigned PUT ditolak browser (403/404/CORS error di console) | laporan user + log edge (request ke host MinIO tidak pernah sampai origin) | ≥ 1 pengguna ⇒ SEV-2; massal ⇒ SEV-1 — **kemungkinan besar §3.3, bukan storage mati** |
| antrian `media` menumpuk / job finalize gagal berulang | Horizon (Blueprint §9; lihat `queue-stuck.md`) | > 200 job ⇒ SEV-2 |
| gambar produk hilang/404 di storefront | Sentry + log nginx | muncul ⇒ SEV-2; jangan sampai checkout ikut mati |
| disk volume MinIO penuh | monitoring kapasitas disk (Blueprint §11) | > 80% ⇒ SEV-2, > 90% ⇒ SEV-1 (satu node, B-15) |
| baris `media` tanpa objek, atau objek tanpa baris `media` | invariant §3.5 | ≥ 1 ⇒ SEV-3 (tapi harus dibereskan) |

## 2. Dampak

Dampak yang benar-benar terjadi biasanya **bukan** kehilangan uang, tapi hilangnya kemampuan bekerja: buyer tidak bisa mengirim foto barang yang diminta → jalur Request Order berhenti; `invoice-documents`/`shipment-documents` tidak terbit → finance dan pengiriman tersendat; `exports` tidak bisa diunduh → laporan tertunda. Checkout **tidak boleh** ikut mati: sasaran test Blueprint §16 eksplisit ("checkout tetap berjalan jika tidak bergantung media"). Kalau media publik 404, storefront tampak rusak padahal transaksi sehat — itu risiko reputasi, bukan risiko transaksi.

## 3. Langkah tanggap

### 3.1 Tentukan: storage mati, jaringan internal, atau URL-nya yang salah

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml ps minio                                     # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio ls -l /data                       # belum diverifikasi — path volume sesuai compose.prod
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml logs --since=30m minio | tail -50           # belum diverifikasi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'head object uji pada disk s3';"                                                            # belum diverifikasi — nama disk/flag belum ada (belum ada kode)
```

`[ verifikasi ]` jawaban salah satu: (i) container MinIO sehat & bisa diakses **dari dalam** jaringan → lanjut §3.3 (masalah endpoint yang dijangkau browser); (ii) container mati/unhealthy → §3.2; (iii) sehat dari dalam tapi API kita pun gagal → kredensial/policy → §3.4.

> Catatan penting Blueprint §11: port MinIO **tidak** boleh terekspos publik (Doc 01 §11.1: admin akses lewat SSH tunnel). Jadi "gagal dari laptop" bukan bukti storage mati. Uji internal dulu.

### 3.2 Nyalakan kembali dengan aman

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml up -d minio                                  # belum diverifikasi di lingkungan ini
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml restart worker                               # belum diverifikasi — worker menyimpan koneksi/SDK yang bisa stale
df -h "$(docker compose -f docker/compose.yaml -f docker/compose.prod.yaml inspect -f '{{ .Mounts }}' minio)"   # belum diverifikasi — bentuk output perlu dicek
```

`[ verifikasi ]` `ps minio` healthy; satu head/put objek uji dari dalam berhasil; error rate kembali ke baseline. Kalau disk penuh: **jangan** menghapus objek untuk melonggarkan — itu merusak bukti (bucket `invoice-documents`, `shipment-documents`, `audit-archives`). Tindakan yang sah: tambah volume (keputusan pemilik infra), atau jalankan lifecycle policy `exports` yang TTL-nya memang sudah ditetapkan (Blueprint §11). Kalau terpaksa memindahkan, pindahkan ke offsite **setelah** §5 (drill) membuktikan salinan bisa dibaca.

### 3.3 Endpoint presigned yang bisa dijangkau browser (penyebab paling sering, dan ini bukan kegagalan storage)

Gejala khasnya: browser menerima presigned URL, PUT gagal, dan di log origin **tidak ada request** ke MinIO sama sekali. Penyebab yang didokumentasikan Blueprint §11: URL memakai hostname internal Docker yang tidak dikenal browser.

```bash
# 1) minta presign sungguhan, lalu baca HOST dari URL-nya (jangan hanya melihat path)
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "
SELECT id, status FROM request_attachments WHERE updated_at > NOW() - INTERVAL '10 minutes' ORDER BY updated_at DESC LIMIT 5;"   # belum diverifikasi — nama tabel/kolom per Doc 11 §4.2, belum diuji
# 2) uji dari LUAR jaringan VPS (laptop/connections lain), bukan dari dalam container
curl -s -o /dev/null -D - "https://<endpoint-yang-muncul-di-URL-presign>/<objek- uji>"     # belum diverifikasi di lingkungan ini
```

`[ verifikasi ]` host pada URL presigned adalah **hostname publik yang tersertiifikat dan bisa di-resolve browser**, bukan `minio:9000` / `localhost:9000` / nama service Docker. Kalau host-nya internal, perbaikannya di konfigurasi (bukan di kode browser):

- endpoint yang dipakai API untuk operasi internal boleh hostname Docker;
- endpoint yang dipakai untuk **presigned URL** (browser yang mengirim PUT) wajib hostname publik — Blueprint §11;
- nama variabel env khusus untuk "endpoint publik" **belum ada** di mana pun yang terdokumentasi. Doc 01 §11.3 menunjuk `.env.example` (deliverable Sprint 0, Doc 03 §3) sebagai satu-satunya daftar variabel, dan daftar minimum yang direncanakan baru berisi `FILESYSTEM_DISK`, `AWS_*` (endpoint MinIO internal) dan `ASSET_URL` — tidak ada var "endpoint untuk presigned URL" → butir terbuka README §5 nomor 6 + **ADR-008**. Jangan menambah var tanpa menuliskannya di `.env.example`; aturan proyek: *tidak ada var yang wajib tapi tidak terdokumentasi* (Doc 01 §11.3).

```bash
# CORS bucket: hanya origin web/admin sah yang boleh PUT/GET langsung
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio mc cat /data/.minio.sys/buckets/<bucket>/cors.xml   # belum diverifikasi — letak file konfigurasi perlu dicek
curl -s -o /dev/null -D - -H "Origin: https://<origin-yang-tidak-seharusnya-boleh>" "https://<endpoint-publik>/<bucket>/<objek>"   # belum diverifikasi
```

`[ verifikasi ]` origin asing **ditolak** oleh CORS/`Access-Control-Allow-Origin` tidak muncul, dan origin aplikasi kita diizinkan. Ini kontrol, bukan kenyamanan (Blueprint §11).

### 3.4 Kebijakan bucket: cek privat vs publik

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio mc alias set local http://minio:9000 '<access-key>' '<secret-key>'   # belum diverifikasi — kredensial dibaca dari env/secret, JANGAN ditulis ke dokumen
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio mc ls local                                                          # belum diverifikasi
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec minio mc anonymous get local/<bucket>                                      # belum diverifikasi
```

Hasil yang diharapkan per bucket (Blueprint §11 / B-9):

| Bucket | Harus |
|---|---|
| `product-media` | publik **hanya via CDN terkendali**; tanpa listing; tanpa bucket privat di bawah path yang sama |
| `request-attachments` | privat; akses hanya signed URL pendek / proxy terautentikasi |
| `invoice-documents` | privat |
| `shipment-documents` | privat |
| `exports` | privat + TTL (lifecycle) |
| `audit-archives` | restricted |

`[ verifikasi ]` `mc anonymous get` untuk lima bucket privat mengembalikan **tidak ada akses publik**, dan tes akses object-level tanpa signed URL gagal. Kalau salah satu bucket privat ternyata publik: **anggap ini insiden keamanan**, bukan insiden ketersediaan — jalankan `data-breach.md` (kunci dirotasi di sisi MinIO, akses diaudit, notifikasi sesuai §4 di sana) dan periksa threat-model T-25/T-24.

Peran `media` yang polimorfik (Doc 09 S-3) berarti satu tabel melayani banyak bucket: jangan membuat baris `media` untuk objek yang validasi MIME/magic-byte-nya belum lulus (Blueprint §11; threat-model T-06).

### 3.5 Baris `media` tanpa objek / objek tanpa baris

```sql
-- kandidat yatim (perlu penyesuaian nama kolom per Doc 09 rewrite)   # belum diverifikasi di lingkungan ini
SELECT m.id, m.disk, m.bucket, m.object_key, m.created_at
FROM media m
WHERE m.created_at > NOW() - INTERVAL '1 day'
  AND m.finalized_at IS NULL;
```

`[ verifikasi ]` setiap baris yang tertinggal punya keputusan: finalize ulang (kalau objeknya memang ada), tandai gagal + hapus objek (kalau objek tidak pernah sampai), atau biarkan dengan catatan (kalau sedang dipakai untuk debug). Jangan menghapus baris `media` yang sudah dirujuk lampiran order — itu memutus bukti (T-30).

### 3.6 Turunkan dampak sambil menunggu pulih

- **Jangan** menonaktifkan checkout. Matikan hanya fitur yang benar-benar bergantung media: form upload lampiran Request Order (flag), dan proses re-encode/moderasi.
- Sampaikan ke `customer-support` bahwa permintaan dengan lampiran akan diminta mengirim ulang **setelah** pulih — bukan memaksa upload lewat jalur lain (email/chat) yang mengeluarkan lampiran dari bucket privat + signed URL (T-24, T-30).
- Untuk dokumen tagihan: tunda penerbitan PDF, jangan mengirim tautan yang tidak sah.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| bucket privat terekspos publik / objek orang lain bisa dibaca | `superadmin` + **klien** + jalankan `data-breach.md` | **segera, SEV-1** |
| akses key MinIO harus dirotasi / dicurigai bocor | pemilik akun infra (**klien**) | segera; rotasi dulu, bicara kemudian |
| disk penuh dan perlu menambah volume | pemilik infra VPS (B-15) | ≤ 1 jam, sebelum write error massal |
| jalur presign publik belum punya var env resmi | penanggung jawab rilis + keputusan **ADR-008** | sebelum implementasi, bukan sesudah |
| objek bukti sengketa hilang permanen | `finance` + `customer-support` + klien | segera, catat sebagai kerugian non-finansial |

## 5. Setelah insiden

1. Angka: jumlah presign/PUT/finalize gagal, bucket terdampak, apakah checkout terpengaruh (harus "tidak"), objek yatim yang dibersihkan, dan waktu pemulihan.
2. Bukti test yang harus ditutup (Blueprint §16/§19):
   - "MinIO unavailable ⇒ upload gagal aman; checkout tetap berjalan jika tidak bergantung media";
   - DoD "MinIO presigned uploads, malware/file validation, dan private access teruji";
   - test kedaluwarsa signed URL + test "signed URL tidak muncul di HTML/log" (T-24).
3. Tiket/ADR & isu:
   - **ADR-008**: daftar bucket + visibilitas + jalur CDN + TTL presigned + var env endpoint publik (README §5 butir 5 & 6);
   - isu lifecycle policy + versioning untuk dokumen kritis (Blueprint §11);
   - isu monitoring kapasitas disk (Blueprint §11) — termasuk volume backup (lihat `backup-restore-drill.md`);
   - isu validasi MIME/magic-byte **menolak** HTML/SVG di `request-attachments` (T-06) bila belum ditegakkan;
   - isu "akses key terpisah per service" kalau satu key dipakai API+worker+backup (Blueprint §11).
4. Perbarui `docs/threat-model.md` T-24/T-25/T-06, dan `docs/runbooks/queue-stuck.md` bila antrian `media` ikut macet.
