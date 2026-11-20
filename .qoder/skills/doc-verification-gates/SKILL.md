---
name: doc-verification-gates
description: How to prove elzara documentation and scaffold claims with the six auditors registered in docs/11-blueprint-reconciliation.md §8.8 instead of asserting them
---

# Doc verification gates

Gunakan skill ini sebelum mengklaim apa pun tentang jumlah, konsistensi, atau
kelengkapan `docs/` dan layout repo. Aturan induknya: **tidak ada angka yang berasal
dari ingatan** — setiap klaim adalah keluaran script yang baru dijalankan.

## Gerbang dan pemiliknya

| Gate | Script | Membuktikan |
|---|---|---|
| A–H | `audit_sweep3.py` | konsistensi lintas dokumen: 13 event, 7 antrian, 6 bucket, 4 sumbu status, 14 grup menu, 10 role, **12 berkas ADR (001–012)** + topik inline tidak salah pasang, sitasi mati, homoglyph, dan kepemilikan tabel (Doc 09 §19 satu-satunya pemilik, Doc 01 §3.1/§3.3 harus cermin) |
| COVER + S1–S3 | `audit_blueprint_coverage.py` | kelengkapan: 29 daftar tangan + tiga check yang **dibaca langsung dari berkas Blueprint** (87 submenu §4, 60 tabel §6, 18 endpoint §7) + scan kalimat yang bertentangan |
| skema | `verify_schema.py` | jumlah blok tabel == tally §19, graf FK asiklik, nama lama tidak tersisa (termasuk pivot lama `role_user`/`permission_role`), tabel §20 terdokumentasi (termasuk `staff_users` dan `personal_access_tokens`), **[4b] keputusan pemilik 2026-11-18**: enam tabel §4.4 ada dan `users` memuat `is_operator`/`is_admin`/CHECK/indeks baru tanpa `kind` |
| G berdiri sendiri | `scan_unicode.py` | karakter CJK/Cyrillic yang nyamar di prosa Indonesia (mode kegagalan berulang di proyek ini); sejak scaffold dipasang ia memindai **seluruh berkas teks repo**, bukan hanya `docs/` |
| F | `list_dead_cites.py` | rujukan `Doc NN §M` yang menunjuk bagian tidak ada |
| I | `check_layout.py` | pohon Doc 01 §3.1 = direktori nyata; tidak ada nama `commerce-*` sisa; tujuh nama antrian dan enam bucket di `docker/` **dan** di kode cocok kanon; dua lockfile dan nol workspace; basename migrasi M-3; 18 jalur Blueprint ada di `openapi.yaml`; 22 modul sama di Doc 01 = Doc 09 = disk |

Script hidup di `C:\temp\` (di luar repo) — direktori itu bisa terhapus; re-create
dari rujukan di Doc 11 §8.8, jangan dari ingatan.

## Cara menjalankan

```bash
ELZARA_AUDITORS_DIR=C:/temp bash scripts/verify-docs.sh   # keenam pemeriksa sekaligus
python C:/temp/audit_sweep3.py
python C:/temp/audit_blueprint_coverage.py
python C:/temp/check_layout.py
python C:/temp/verify_schema.py
```

Verdict yang benar-benar hijau, persis:

- `LULUS — semua pemeriksaan A–H bersih`
- `LULUS — COVER (29 daftar) + S1-S3 + KONTRADIKSI bersih`
- `LULUS — <n> tabel, konsisten dengan Doc 11.` — **`<n>` diproduksi parser dari Doc 09 §19**;
  jangan menulis angkanya di dokumen mana pun, termasuk di skill ini
- `LULUS — pohon Doc 01 §3.1 = filesystem, dan tujuh aturan layout ditegakkan di berkas`
- `selesai: 0 kemunculan mencurigakan`
- gate F: 0 temuan

## Kalau gerbang menggigit

Jangan melemahkan matcher. Triase dulu:

1. **Temuan nyata** → perbaiki dokumennya (biasanya prosa usang atau klaim tanpa sumber).
2. **Salah bahasa** → token Indonesia vs Inggris (mis. "antrian" vs `queue`) — buat matcher
   sadar konteks, bukan longgar.
3. **Konteks rename/bantahan** → kalimat yang menyebut nama lama justru untuk menolaknya;
   kecualikan lewat aturan eksplisit yang ditulis di script, lengkap dengan alasannya.

**Harapan boleh berubah, pencocokan tidak.** Kalau skema berubah (mis. keputusan pemilik
2026-11-18 mengganti pivot RBAC dan menambah dua tabel), yang diubah adalah konstanta
harapan di gate H/E dan daftar nama mati di gate D — secara sengaja, disebut di commit,
bersamaan dengan Doc 09 §19 dan Doc 01 §3.3. Menghapus pemeriksaan supaya hijau adalah
kegagalan, bukan perbaikan.

Kata kunci topik ADR harus **membedakan**, bukan generik: `auth` dan `bypass` sengaja
tidak dipakai karena menyentuh lebih dari satu ADR (007 mekanisme sesi, 009 waiting-room
bypass, 012 RBAC bypass). Penamaan inline yang aman: `ADR-007 (BFF/sesi)`,
`ADR-012 (RBAC/identitas/dependensi)`.

Setiap nomor bagian Doc 11 yang berubah menggeser seluruh rujukan `Doc 11 §8.N` di repo:
pakai satu pass dengan pemetaan (`renumber_doc11.py`), dan buat gate mencocokkan
**judul** heading, bukan nomornya.