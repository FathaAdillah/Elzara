# Dokumentasi: pemeriksa dalam repo

Rekonstruksi stdlib Python 3.12+ dari Doc 11 §8.8 dan sumber yang disebutnya.
Implementasi asli tidak tersedia. Ini bukan port yang terbukti identik.

```sh
bash scripts/verify-docs.sh
ELZARA_BLUEPRINT_PATH=/path/to/SNOWEGO_MICROSERVICES_BLUEPRINT.md bash scripts/verify-docs.sh
python3 scripts/doc-auditors/test_auditors.py -v
```

`PYTHON` memilih interpreter; `ELZARA_AUDITORS_DIR` override opsional direktori
skrip. Root repo selalu ditemukan dari lokasi skrip, bukan cwd pemanggil.
Pengujian menjalankan skrip asli pada salinan repo sementara dengan cacat tanam;
fixture Blueprint sintetis hanya menguji parser, bukan membuktikan isi sumber asli.

## Sumber dan batas klaim

- Semua inventaris eksplisit ada di `expectations.py`, dengan komentar sumber.
- Gate C mengikuti **24 event Doc 12 §6.2**. Angka 13 pada skill historis usang;
  referensi nama terdaftar di dokumen lain tetap sah menurut Doc 11 §8.4.
- COVER menghitung daftar yang benar-benar direkonstruksi: grup menu, submenu
  setiap grup, role, antrian, bucket, runbook, event, kandidat dokumen, endpoint,
  inventaris skema, empat sumbu status, dan berkas ADR. Tidak mengasumsikan 29.
- Tanpa `ELZARA_BLUEPRINT_PATH`, S1 membandingkan Doc 06 §2.2, S2 seluruh daftar
  Doc 09 §19 (bukan memilih sembarang 60 nama), S3 inventaris Doc 12/CI.
  Hasilnya **LULUS SEBAGIAN**, exit 0; Blueprint tidak dibaca.
- Dengan sumber, parser menuntut bagian Markdown bernomor 4, 6, 7. Menu dapat
  berbentuk tabel grup/submenu atau heading grup dengan bullet submenu; tabel
  skema berupa bullet identifier atau kolom pertama tabel; endpoint berupa path
  ber-backtick atau token path berdiri sendiri. Format lain gagal tertutup.
  Tata letak berkas asli belum dapat diuji. Pengecualian tabel hanya tiga nama
  tertulis Doc 11 §4.3/§8.5, yang wajib tetap dijelaskan Doc 09 §20.
- Inventaris endpoint CI memuat health operasional selain 17 path bisnis pada
  tabel Doc 12 §2.1. Keduanya dinyatakan terpisah dalam dokumen itu.
- Gate I memakai pembaca subset YAML block-style untuk inventory `paths`, bukan
  parser YAML umum. Pemeriksa ini tidak memverifikasi implementasi fitur.

## Aturan dugaan yang wajib disebut di PR

1. **EVENT_LEXICAL_BOUNDARY** (`EVENT_SUFFIXES`): pengenal CamelCase berakhiran
   event konvensional atau sesudah kata `event`. Dokumen menetapkan nama yang
   sah, tetapi tidak memberi grammar pengenal event di semua prosa. Nama asing
   dengan bentuk lain tanpa penanda event dapat luput. Domain exception dari
   register Doc 12 §4.3 dan command `NotifyEvent` bukan event.
2. **CONTRADICTION_SENTENCE**: segmentasi kalimat/tabel dan pola klaim positif
   Indonesia adalah rekonstruksi; dokumen memberi konflik dan resolusinya,
   bukan grammar matcher. Ini scan kalimat kontradiktif yang dikenal, bukan
   pembuktian semantik semua parafrasa. Negasi hanya meniadakan klaim yang
   langsung mengikutinya, tidak kalimat berikutnya.
3. **INLINE_ADR_TOPIC_LABEL**: label topik diawali kata pembeda ADR. Posisi kata
   ini direkonstruksi dari contoh Doc 11/skill; parenthesis berisi uraian dampak
   (misalnya TTL mengikuti gateway) bukan label ADR gateway. Kata topik yang
   disisipkan di tengah uraian tidak diklasifikasikan sebagai label.

Ketiganya batas rekonstruksi, bukan pengecualian tambahan untuk membuat temuan
aktif menjadi hijau. Tidak ada nama aktif yang di-whitelist tanpa sumber.

## Pengecualian bernama dengan sumber tertulis

Aturan berada di dekat matcher dan mengutip sumbernya:

- `OLD_DOCUMENT_COLUMN`, `RENAME_MAP_CELL`, `MODULE_RENAME_COLUMN`,
  `OLD_STATUS_MAPPING_CELL`, `SPRINT_TRACE_OLD_COLUMN`: hanya sel historis
  tabel perbandingan/rename, bukan kolom resolusi yang masih aktif.
- `EXPLICIT_RENAME_ARROW`, `EXPLICIT_REBUTTAL`, `PAIRED_RENAME`,
  `OLD_COLUMN_DELETION`: token lokal yang secara eksplisit diganti/ditolak.
- `WRITTEN_MODULE_RENAME`, `ADR_OLD_PIVOT_REASON`,
  `WRITTEN_EVENT_RETRACTION`: frasa historis persis, bukan seluruh paragraf.
- `PLAIN_ENGLISH_NOUN`, `ENGLISH_MENU_LABEL`, `ROLE_READ_ONLY_LABEL`:
  label menu Inggris bukan nama tabel/modul; kode yang di-backtick tetap dicek.
- `IDENTIFIER_BOUNDARY`: komponen path ber-hyphen bukan identifier SQL lama.
- `DOMAIN_EXCEPTION_NOT_EVENT`, `EVENT_RENAME_RECORD`, `TEST_ROW_RANGE`:
  peta exception, rename event, dan rentang test tertulis.
- `TREE_FOREIGN_REFERENCE`, `OWNERSHIP_REBUTTAL`, `TREE_REBUTTAL`, `TREE_BRACES`:
  anotasi tree dan cermin tabel membedakan klaim kepemilikan dari penjelasan.
- `REJECTED_ADR_ALTERNATIVE`, `EXPLICIT_NEGATED_CLAIM`: alternatif yang ditolak
  dan klaim yang dibantah bukan keputusan yang masih berlaku.
- `LAYOUT_RENAME_RECORD`, `LAYOUT_GATE_SPEC`, `PLAN_RENAME_RECORD`,
  `SKILL_GATE_SPEC`, `AUDITOR_RULE_LITERALS`: contoh nama layout terlarang.
- `SCHEMA_EXPLANATION`, `PLAIN_BACK_EDGE`, `MARKDOWN_EMPHASIS`:
  komentar skema, penolakan FK eksplisit, dan format Markdown.

Setiap perubahan harapan harus ditinjau. Jangan memperlebar pengecualian untuk
meloloskan temuan; perbaiki dokumen bila klaim aktifnya salah.
