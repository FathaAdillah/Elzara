# @elzara/api-client

`openapi.yaml` adalah satu-satunya sumber kontrak `/api/v1` (Doc 12 §1.1); wujud manusianya `docs/12-api-contract.md`.
`generated/` DI-COMMIT dan TIDAK PERNAH diedit tangan — kalau bentuk hasilnya jelek, perbaiki `openapi.yaml` (D-S0-5, Doc 12 §1.3).
Regenerasi: `npm run codegen` (atau `scripts/codegen.sh`) di dalam image `node:22`; `scripts/check-contract-drift.sh` adalah gerbang CI-nya.
PR yang mengubah controller/route tanpa mengubah `openapi.yaml` wajib gagal di CI (ADR-010 K-3, Doc 12 §1.2 G1).
Auth sudah diputuskan (2026-11-18): cookie sesi Sanctum same-site, guard `web` (`elzara_web_session`) dan `admin` (`elzara_admin_session`) terpisah, CSRF wajib pada setiap mutasi (`X-XSRF-TOKEN`, didahului `GET /sanctum/csrf-cookie`); `personal_access_tokens` tidak dibuat (Doc 11 §4.4 C, ADR-007 (BFF/sesi) amandemen).
`security` per operasi wajib dan `securitySchemes` sudah ada (`webSession`, `adminSession`, `s2sSignature`); pengecualian lama "menunggu ADR-007" dicabut (Doc 11 §4.4 I). `x-elzara-auth` tetap penanda kategori; operasi admin menambah `x-elzara-permission: <modul>.<aksi>` (prefix kanonik: Doc 11 §4.4 B).
`dedoc/scramble` hanya alat bantu DRAF spec yang direview manusia lalu digabungkan ke sini; spec tidak pernah di-generate ulang otomatis di CI (Doc 11 §4.4 E).
