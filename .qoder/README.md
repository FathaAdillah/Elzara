# .qoder/

Direktori kerja agen untuk proyek ini. Isinya bagian dari repo supaya agen berikutnya
memulai dari konteks yang sama, bukan dari nol.

| Direktori | Isi |
|---|---|
| `skills/` | skill tingkat proyek (aturan yang terlalu panjang untuk diulang di setiap brief). Satu skill = satu folder ber-`SKILL.md`. |
| `agents/` | definisi agen pelaksana: peran, lingkup direktori yang boleh ditulis, dan gerbang yang harus hijau sebelum ia berhenti. Lima peran (`migration-db`, `feature`, `knowledge`, `testing`, `docker-rebuild`) + `README.md` yang memuat urutan mengikatnya. |
| `plans/` | plan development yang dieksekusi, nama `YYYY-MM-DD-<sub-proyek>.md`. Satu task = satu subagent; dua penulis tidak pernah memegang direktori yang sama. |

## Yang TIDAK pindah ke sini

- **Dokumen pengetahuan** tetap di `docs/` — `docs/` adalah rujukan yang dibaca manusia
  dan klien; plan adalah instruksi eksekusi. Perbedaannya bukan sekadar lokasi.
- **Spec** tetap di `docs/specs/` karena ia adalah artefak sign-off klien (Doc 03 P-6),
  bukan catatan kerja.
- Skill yang disediakan plugin/global tidak disalin ke sini; yang ditulis di sini hanya
  aturan khas proyek ini (mis. cara menjalankan gerbang verifier, format basename
  migrasi M-3, daftar antrian yang diizinkan).

## Urutan agen yang mengikat (keputusan pemilik 2026-11-18, Doc 11 §4.4 H)

`migration-db` → `feature` → `testing` → `docker-rebuild`. Agen testing **tidak boleh
mulai** sebelum agen fitur selesai, dan rebuild docker adalah gerbang terakhir — bukan
pekerjaan sampingan yang jalan kapan saja. Penulis knowledge berjalan paralel, tetapi
hanya pada berkas yang tidak bersentuhan; matriks kepemilikan ditulis di brief, bukan
disimpulkan dari nama direktori.

Pengendali (sesi utama) memegang `docs/11-blueprint-reconciliation.md`, `docs/00-overview.md`,
`docs/adr/*`, dan skrip pemeriksa di `C:\temp`. Agen pelaksana tidak mengubah register
kanonik atau pemeriksa: kalau keduanya tampak salah, ia melapor, bukan menyimpang diam-diam.

## Konvensi

Setiap plan di `plans/` harus dimulai dengan blok **Ownership matrix** (direktori → task)
sebelum task pertama, karena tabrakan antar agen hanya bisa dicegah kalau pembagian
filenya tertulis, bukan disimpulkan. Plan yang task-nya sudah tertutup diberi catatan
penutup pada task terakhir, tidak dihapus — sejarah keputusan lebih berguna daripada
daftar hijau.
