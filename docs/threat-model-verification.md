# Register Verifikasi Threat Model — Elzara

Tanggal: 2026-11-22 · Status: **inventaris bukti, bukan kelulusan seluruh kontrol**.

## 1. Dasar dan batas klaim

Sumber: [threat model](threat-model.md) §1–§7, Task 19 pada `.codex/plans/2026-11-17-sprint-0-fondasi.md`, Doc 03 0.13/1.10 dan ADR-007 D-6a. Task 19 Step 1 menentukan format `id T-n | kontrol | berkas test | perintah | hasil | status`; Step 2 melarang menaikkan kontrol tanpa bukti.

Inspeksi kode dan **badan tes** pada basis PR #39 `6fff64c`; cabang ini hanya mengubah dokumentasi. **Suite aplikasi/checker endpoint tidak dijalankan dalam audit ini.** Hasil tabel adalah asersi yang tersedia, bukan output run baru. Workflow dengan filter paths belum tentu berjalan pada PR docs-only. **Pembaruan 2026-11-22:** config sesi/nginx/Sanctum dan middleware diperiksa kembali pada cabang setelah b762e6b untuk amandemen ADR-007 D-6a; fakta konfigurasi bukan bukti deployment atau pengujian dua host.

Sufiks T-n.a/b memecah penegak berbeda pada ancaman yang sama; seluruh T-01…T-42 tercakup. Satu baris menyatukan kontrol yang berbagi penegak/cakupan bukti; gap/tindakan yang belum terbukti ditulis eksplisit. S-1…S-8 menambahkan cakupan §/batas yang melampaui satu ancaman, termasuk cookie/CSRF/2FA/guard separation.

- **teruji**: ada asersi langsung untuk lingkup yang disebut; bukan klaim run baru atau seluruh ancaman selesai.
- **sebagian**: ada sebagian bukti, tetapi lingkup lebih luas belum terbukti atau checker belum menjadi gate runtime CI.
- **tanpa tes**: ⚑ belum ditemukan pembuktian; tidak otomatis berarti kode tidak ada.
- **belum diimplementasi**: fitur/penegak bisnis masih desain. Fixture fondasi tidak disamakan dengan produksi.
- Label `[desain]` threat model adalah baseline resep; status granular terkini ada di register ini. Tidak menaikkan seluruh T-n ke `[terpasang]` jika masih memiliki gap.

## 2. Berkas dan perintah

Nama kelas §3 merujuk pada path berikut (relatif root). Semua referensi method telah dicocokkan dengan deklarasi; asersinya dibaca untuk membatasi klaim.

| Kelas | Berkas test |
|---|---|
| AdminCatalogCrudTest | `apps/api/tests/Feature/Catalog/AdminCatalogCrudTest.php` |
| AdminCatalogScopeTest | `apps/api/tests/Feature/Catalog/AdminCatalogScopeTest.php` |
| AdminFlagIdempotencyTest | `apps/api/tests/Feature/Settings/AdminFlagIdempotencyTest.php` |
| AdminLoginTest | `apps/api/tests/Feature/Identity/AdminLoginTest.php` |
| ApiConventionsTest | `apps/api/tests/Feature/Platform/ApiConventionsTest.php` |
| AuthSchemaTest | `apps/api/tests/Schema/AuthSchemaTest.php` |
| AuthSecurityGatesTest | `apps/api/tests/Feature/Auth/AuthSecurityGatesTest.php` |
| CartCheckoutTest | `apps/api/tests/Feature/Cart/CartCheckoutTest.php` |
| DemoAssetProvenanceTest | `apps/api/tests/Unit/DemoAssetProvenanceTest.php` |
| EnumCheckConstraintTest | `apps/api/tests/Feature/Platform/EnumCheckConstraintTest.php` |
| IdempotencyGuardTest | `apps/api/tests/Feature/Platform/IdempotencyGuardTest.php` |
| IdempotencyLockTest | `apps/api/tests/Concurrency/Platform/IdempotencyLockTest.php` |
| InboxConsumerTest | `apps/api/tests/Feature/Platform/InboxConsumerTest.php` |
| KillSwitchTest | `apps/api/tests/Feature/Settings/KillSwitchTest.php` |
| MediaBackupTest | `apps/api/tests/Feature/Content/MediaBackupTest.php` |
| MediaUploadTest | `apps/api/tests/Feature/Content/MediaUploadTest.php` |
| ObservabilityTest | `apps/api/tests/Feature/Platform/ObservabilityTest.php` |
| OutboxDispatchTest | `apps/api/tests/Feature/Platform/OutboxDispatchTest.php` |
| ParallelHarnessTest | `apps/api/tests/Concurrency/Harness/ParallelHarnessTest.php` |
| ProductSearchTest | `apps/api/tests/Feature/Search/ProductSearchTest.php` |
| StaffRbacTest | `apps/api/tests/Feature/Identity/StaffRbacTest.php` |
| StateMachineTransitionTest | `apps/api/tests/Feature/Platform/StateMachineTransitionTest.php` |
| StorefrontAuthTest | `apps/api/tests/Feature/Identity/StorefrontAuthTest.php` |

**API(Kelas):** dari `apps/api` dengan PHP 8.4/dependensi proyek: `php artisan test --filter=Kelas` (ganti Kelas pada sel bukti). Untuk beberapa kelas jalankan masing-masing filter. Feature/schema/concurrency memakai PostgreSQL 16/Redis; media/search juga perlu live MinIO/Elasticsearch. Tes media dapat **skip** bila `MINIO_TEST_ENDPOINT` tidak tersedia; skip bukan lulus kontrol. Wiring `.github/workflows/api.yml` menjalankan Unit, Feature/Contract/Schema dan Concurrency serta init MinIO/search.

**Admin:** dari `apps/admin`, `npm test`; `.github/workflows/admin.yml` menjalankan runner node:test. Referensi test JS di tabel adalah judul test persis; mock bukan browser.

| Gate / checker | Perintah, lokasi dan batas |
|---|---|
| gate edge | `.github/workflows/edge.yml` → `bash scripts/validate-edge-nginx.sh`, hanya `nginx -t` dev/dokploy; bukan uji HTTP rate-limit/header/Cloudflare. |
| Checker | `python3 scripts/check-edge-headers.py --base-url <origin-uji> --https-edge` dengan host/origin sesuai lingkungan. `check` menguji CSP/XFO/HSTS/no-store, `main` nonce baru dan kedua surface; memerlukan stack hidup, tidak dipanggil workflow edge. |
| gate compose | `.github/workflows/contracts.yml` job `compose`: perintah render Compose + jq di setiap step memeriksa no-host-port/internal PostgreSQL/Redis/MinIO/Elasticsearch, env inventory, enam bucket dan backup-egress. Gunakan fixture env CI; bukan env produksi. |
| gate kontrak | `.github/workflows/contracts.yml` job `openapi`/`drift`: validasi OpenAPI, amount_minor integer/currency IDR dan `bash scripts/check-contract-drift.sh`; bukan bukti semua SQL/uang DB. |
| gate arsitektur | Dari `apps/api`: `composer deptrac && composer deptrac:b2`, konfigurasi `deptrac.yaml`/`deptrac-b2.yaml`, wiring `.github/workflows/api.yml`. Dependency gate bukan seluruh perilaku B1–B6. |
| gate rantai pasok | `.github/workflows/api.yml`: composer lint/analyse/audit --locked dan build Docker; `.github/workflows/admin.yml`/`web.yml`: npm ci/audit --omit=dev dan build. Bukan SBOM/provenance. |
| gate dokumen | `PYTHON=~/.local/bin/python3.12 bash scripts/verify-docs.sh`, wiring `.github/workflows/contracts.yml` job docs. Konsistensi docs bukan tes aplikasi. |

## 3. Register kontrol

Perintah **API** = API(Kelas) §2 untuk setiap kelas di sel bukti. **Gate/Checker/Admin** mengikuti §2; **—** = tidak ada tes yang bisa diklaim. Semua hasil di bawah: **inspeksi, belum dieksekusi ulang**.

| id T-n / § | kontrol | berkas test / method atau gate | perintah | hasil inspeksi dan gap | status |
|---|---|---|---|---|---|
| T-01.a | Password/lockout akun | AuthSecurityGatesTest::test_sixth_failed_admin_login_is_rate_limited; StorefrontAuthTest::test_login_lockout_counts_failures_per_account_and_relogin_replaces_the_device_row | API | 429 setelah ambang admin/web; bukan bot defense terdistribusi. | teruji |
| T-01.b | OTP Argon2id, sekali pakai, anti-enumerasi | StorefrontAuthTest::test_otp_is_argon2id_single_use_and_anti_enumeration | API | Kode salah/ulang ditolak; respons email dikenal/tidak dikenal setara. | teruji |
| T-01.c | Edge+API throttle semua login/OTP/reset, backoff, WAF dan alert | ApiConventionsTest::test_rate_headers_cover_success_errors_and_limit_exhaustion; gate edge | API + Gate | API fixture teruji; nginx -t bukan uji throttle HTTP/WAF staging, seluruh OTP/reset atau alert. | sebagian |
| T-01.d | 2FA operator dan email terverifikasi sebelum checkout | AdminLoginTest::test_pending_challenge_becomes_verified_admin_session_and_logout_revokes_it | API | Challenge 2FA diuji, bukan persyaratan email seluruh checkout; MFA customer belum diputus. | sebagian |
| T-02.a | Cookie dua guard Secure/HttpOnly/Lax/Path=/, rotasi sesi setelah 2FA | AuthSecurityGatesTest::test_web_session_cookie_has_production_security_attributes; AuthSecurityGatesTest::test_totp_authenticates_the_guard_and_rotates_session_and_admin_cookie_cannot_authenticate_web | API | Asersi atribut production dan ID sesi berubah; helper memakai localhost, tidak mengasersi Domain atau browser dua host. | teruji |
| T-02.b | Isolasi guard web/admin (bukan isolasi pengiriman cookie antar host) | StorefrontAuthTest::test_login_accepts_any_active_account_and_stays_off_the_admin_guard; AuthSecurityGatesTest::test_totp_authenticates_the_guard_and_rotates_session_and_admin_cookie_cannot_authenticate_web | API | Kedua arah tidak mengautentikasi guard lain; cookie array tes tidak menerapkan scope domain browser. | teruji |
| T-02.c | Logout/reset/revoke sesi owner | StorefrontAuthTest::test_logout_revokes_session_and_suspension_ends_an_open_session; StorefrontAuthTest::test_password_reset_is_single_use_and_revokes_every_session; StorefrontAuthTest::test_sessions_are_listed_and_revoked_only_by_their_owner | API | Sesi lama tidak berlaku; DELETE sesi orang lain 404; bukan drill insiden. | teruji |
| T-02.d | Redis sesi, tanpa PAT/bearer browser/secret NEXT_PUBLIC, rotasi naik hak dan TTL | — | — | ⚑ Belum gate menyeluruh penyimpanan Redis, larangan token/secret bundle dan semua pemicu rotasi/TTL. | tanpa tes |
| T-02.e | Host-only sesi/challenge per host, tanpa Domain induk; tidak ada cookie silang saat cutover | AuthSecurityGatesTest::test_web_session_cookie_has_production_security_attributes; AuthSecurityGatesTest::test_totp_authenticates_the_guard_and_rotates_session_and_admin_cookie_cannot_authenticate_web; config/session.php dan SelectAdminSession (path lengkap ADR-007) | API + inspeksi config | Atribut/guard teruji pada localhost; admin memaksa domain null, web env-overridable. Tidak ada asersi Domain absent atau browser dua host. Pagar penerbitan host dan pembersihan cookie induk lama belum ada/teruji. | sebagian |
| T-03.a | CSRF login/logout admin dan mutasi katalog | AdminLoginTest::test_login_enforces_csrf_password_and_operator_status; AdminLoginTest::test_pending_challenge_becomes_verified_admin_session_and_logout_revokes_it; AdminCatalogCrudTest::test_permissions_csrf_and_idempotency_guard_every_mutation | API | 419 pada operasi yang diasersi; bukan matriks semua rute. | teruji |
| T-03.b | Bootstrap CSRF client, reason dan idempotency key unik | apps/admin/tests/admin-api.test.ts — admin requests bootstrap CSRF, send JSON reasons and unique idempotency keys without retries | Admin | Mock $fetch membuktikan urutan bootstrap/header, bukan browser nyata. | teruji |
| T-03.c | Seluruh mutasi CSRF, XSRF readable, origin/Fetch-Metadata, CORS per surface | — | — | ⚑ Belum matriks route×guard×origin; subdomain dipilih, re-approval amandemen tercatat 2026-11-22; implementasi pending PR #45; penegakan origin/Host/CORS belum ada (T-03.e/f). | tanpa tes |
| T-03.d | Key idempotensi mutasi finansial | IdempotencyGuardTest::test_authentication_and_key_validation_precede_the_effect | API | Middleware fixture; Orders/Payments uang belum terintegrasi. | sebagian |
| T-03.e | Binding Host route/bootstrap admin dan admin-only Origin/Referer + token | —; inspeksi SelectAdminSession, VerifySessionCsrf, config/sanctum.php, nginx routes.inc (ADR-007 D-6a) | — | [desain amandemen 2026-11-22] Selector path bukan Host; Sanctum daftar gabungan dan Referer-first, token middleware tanpa allowlist origin. Pagar menolak storefront/null/asing/konflik/tanpa origin atau referer belum diimplementasi; tes normal/negatif dibutuhkan. | belum diimplementasi |
| T-03.f | CORS per surface, origin sendiri saja, tanpa credentialed cross-origin browser API | —; inspeksi default Laravel config/cors.php di dependensi, config aplikasi tidak memiliki override | — | [desain amandemen 2026-11-22] Default allowed_origins=* dan supports_credentials=false bukan allowlist admin/web terpisah. Belum penegakan/gate Host×route×Origin/preflight. API browser tetap path same-origin per host, bukan subdomain API baru. | belum diimplementasi |
| T-03.g | XSRF-TOKEN admin host-only/Secure/Lax/Path=/, readable, tidak berbagi token web | AdminLoginTest::test_login_enforces_csrf_password_and_operator_status; apps/admin/tests/admin-api.test.ts — admin requests bootstrap CSRF, send JSON reasons and unique idempotency keys without retries | API + Admin | ⚑ Tes token dasar/mock bukan bukti scope domain browser. Framework mewariskan domain sesi, tetapi bootstrap belum terikat host. Belum tes dua host Domain absent, header token silang ditolak, dan Origin/Referer negatif; jangan klaim lulus dari jar array. | tanpa tes |
| T-04.a | XFO DENY dan frame-ancestors none | scripts/check-edge-headers.py::check; gate edge | Checker | Checker respons nyata tersedia; CI edge hanya nginx -t, bukan run checker. | sebagian |
| T-05.a | UGC order selesai, moderasi, escaping/sanitizer | — | — | UGC/order selesai belum tersedia; tes XSS/moderasi belum ada. | belum diimplementasi |
| T-06.a | UUID key, MIME/magic-byte dan batas ukuran | MediaUploadTest::test_real_put_finalize_replay_and_private_versioned_download; MediaUploadTest::test_finalize_rejects_invalid_objects; MediaUploadTest::test_png_claimed_as_jpeg_is_rejected_and_actual_png_records_dimensions; MediaUploadTest::test_presign_rejects_bad_mime_size_bucket_and_empty_input | API | Upload generik admin; bukan route Request Order. | teruji |
| T-06.b | Re-encode/antivirus/async lampiran Request Order | — | — | Finalize generik bukan bukti pipeline tersebut atau ownership Request Order. | belum diimplementasi |
| T-06.c | Download privat singkat dan owner media | MediaUploadTest::test_real_put_finalize_replay_and_private_versioned_download; MediaUploadTest::test_missing_unpresigned_and_unowned_objects_cannot_be_finalized_or_signed | API | Unsigned 403, unowned 404; versi unduhan tetap setelah PUT ulang. | teruji |
| T-07.a | CSP self+nonce tanpa unsafe-inline/eval; no-store HTML | scripts/check-edge-headers.py::check; scripts/check-edge-headers.py::main | Checker | Asersi nonce per script dan beda antar-request tersedia; checker bukan gate runtime CI. | sebagian |
| T-07.b | Tidak ada secret NEXT_PUBLIC | — | — | ⚑ Tidak ditemukan scanner bundle browser/secret menyeluruh. | tanpa tes |
| T-08.a | Permission server-side granular, direct grant dan revocation | StaffRbacTest::test_direct_grants_and_revocation_take_effect_in_the_same_session; StaffRbacTest::test_role_bundles_differ_on_access_routes | API | Settings/access teruji; bukan 10 role × seluruh modul yang belum tersedia. | sebagian |
| T-08.b | Scope baca/tulis katalog | AdminCatalogScopeTest::test_reads_are_limited_to_the_scoped_category_subtree; AdminCatalogScopeTest::test_writes_outside_the_scoped_subtree_are_forbidden | API | Termasuk ekspor scoped dan penolakan mutasi di luar subtree; bukan seluruh modul. | teruji |
| T-08.c | AuditThis setiap mutasi admin | StaffRbacTest::test_permission_endpoints_audit_before_after_and_replay_without_duplicate_mutations | API | Audit grant/revoke dan replay tunggal, bukan seluruh aksi berisiko. | sebagian |
| T-09.a | Operator aktif+2FA, API menolak sebelum akses | AdminLoginTest::test_login_enforces_csrf_password_and_operator_status; AdminLoginTest::test_pending_challenge_becomes_verified_admin_session_and_logout_revokes_it | API | API/challenge teruji; bukan seluruh SSR shell admin. | sebagian |
| T-09.b | Tamu tidak menerima shell 200, otorisasi bukan menu tersembunyi | — | — | ⚑ Belum tes HTTP/browser semua halaman admin; tes menu kosmetik bukan bukti. | tanpa tes |
| T-10.a | Signature, merchant/amount/currency/reference, arsip body dan pengecualian webhook WR | — | — | Driver payment belum ada (Q-7); rate-policy webhook bukan signature. | belum diimplementasi |
| T-11.a | UNIQUE receipt/capture, ACK async, transisi pembayaran dan replay/out-of-order | — | — | Inbox/runner fixture bukan bukti satu payment capture produksi. | belum diimplementasi |
| T-12.a | Actor/operation/hash, payload beda 409, replay byte sama | IdempotencyGuardTest::test_replay_preserves_original_body_status_and_headers_and_conflict_has_no_effect | API | Efek fixture satu, key actor/operasi lain independen. | teruji |
| T-12.b | Claim+efek rollback/retry dan client-error replay | IdempotencyGuardTest::test_recoverable_failures_leave_no_claim_or_effect_and_retry_succeeds; IdempotencyGuardTest::test_client_errors_are_replayed_without_preserving_their_database_effects | API | 503 tanpa claim/efek; 422 replay tanpa efek DB. | teruji |
| T-12.c | Same-key konkuren unique/lock | IdempotencyLockTest::test_independent_connections_cannot_apply_the_same_key_twice | API | Dua koneksi PostgreSQL fixture; bukan checkout produksi. | teruji |
| T-13.a | Key sama lintas aktor tidak meracuni slot | IdempotencyGuardTest::test_replay_preserves_original_body_status_and_headers_and_conflict_has_no_effect | API | Actor kedua membuat efek terpisah. | teruji |
| T-14.a | Conditional update stok/nonnegatif | ParallelHarnessTest::test_parallel_clients_preserve_capacity | API | Fixture stok, capacity/conservation; bukan stock_items checkout produksi. | sebagian |
| T-14.b | Ledger immutable, lock SKU konsisten, retry deadlock bounded, cache bukan stok, 1.000 checkout | — | — | Orders/reservasi produksi dan gate staging belum tersedia. | belum diimplementasi |
| T-15.a | Reserve kuota atomik paralel | ParallelHarnessTest::test_parallel_clients_preserve_capacity | API | Fixture kuota 50; bukan enrollment/status/waktu PoBatch. | sebagian |
| T-15.b | Guard waktu/open, close lock+snapshot dan 500 enrollment | — | — | PoBatch/enrollment produksi belum tersedia. | belum diimplementasi |
| T-16.a | Batas akun, abuse/bot defense, drop claim unik, honeypot dan WAF/WR | — | — | Drops/group order belum ada; limiter umum bukan bukti Sybil. | belum diimplementasi |
| T-17.a | Rate-limit katalog origin | ApiConventionsTest::test_every_api_route_declares_one_known_surface_and_its_policy; ApiConventionsTest::test_storefront_server_calls_are_limited_per_forwarded_shopper; gate edge | API + Gate | Policy rute dan shopper proxy diuji; bukan WAF/Bot Management staging. | sebagian |
| T-17.b | Cache katalog singkat, WAF/Bot dan hormati robots sumber | — | — | ⚑ Tidak ada bukti WAF aktif/cache CDN atau audit proses observasi. | tanpa tes |
| T-18.a | Quotation owner/grant staff, scoped query, ID opaque dan tidak terindeks publik | — | — | Quotation belum dibangun; cart owner bukan bukti quotation. | belum diimplementasi |
| T-19.a | Ekspor customer RBAC analyst/read-only + audit | — | — | Ekspor katalog scoped bukan ekspor PII customer. | belum diimplementasi |
| T-19.b | Redaction log dan ekspor privat TTL/at-rest | ObservabilityTest::test_log_processor_redacts_pii_and_hashes_user_ids; MediaUploadTest::test_bucket_inventory_permissions_cors_and_private_console | API | Redaction dan exports 403/lifecycle 7 hari diuji; enkripsi-at-rest/proses ekspor belum. | sebagian |
| T-20.a | Emergency disable checkout | KillSwitchTest::test_disabled_rejects_before_validation_or_dispatch_with_localized_problem | API | 503 lokal sebelum validasi/dispatch; PlaceOrder masih stub. | teruji |
| T-20.b | WR admission, backpressure/pool/resource limits, partition SKU, growth PgBouncer/replica | — | — | ⚑ Kapasitas/saturation/admission staging dan k6 belum teruji. | tanpa tes |
| T-20.c | Health/metrics/alert p95, queue, DB dan storage | ObservabilityTest::test_metrics_endpoint_exposes_request_queue_and_database_series; ObservabilityTest::test_metrics_endpoint_is_hidden_from_public_clients | API | Metrik dan endpoint privat diuji; bukan alert/paging/kapasitas staging. | sebagian |
| T-21.a | Origin bypass/WR API dan pengecualian webhook/health aman | ApiConventionsTest::test_internal_access_uses_configured_cidrs_and_hides_routes; gate edge | API + Gate | Fixture CIDR dan nginx -t; bukan firewall Cloudflare/bypass checkout staging. | sebagian |
| T-22.a | Strict input allowlist body/query | ApiConventionsTest::test_strict_validation_rejects_body_and_query_unknown_fields | API | Unknown field ditolak; bukan seluruh SQL terparameterisasi. | teruji |
| T-22.b | Query builder, larangan raw interpolasi, sort allowlist, tes injeksi setiap filter dan C1-C6 | gate arsitektur; gate kontrak | Gate | Dependency/kontrak statis bukan SQL parser, ZAP atau seluruh filter. | sebagian |
| T-23.a | Harga/snapshot dihitung ulang server-side | CartCheckoutTest::test_add_merge_update_remove_stock_and_repricing; gate kontrak | API + Gate | Cart repricing dan amount_minor integer/currency IDR spec; bukan semua diskon/pajak/order aggregate. | sebagian |
| T-23.b | Empat sumbu transisi sah dan rollback sink | StateMachineTransitionTest::test_rejected_transitions_do_not_change_the_row_or_write_history; StateMachineTransitionTest::test_sink_failure_rolls_back_audit_and_transition_together; EnumCheckConstraintTest::test_every_enum_case_is_accepted_and_invalid_or_empty_values_violate_each_check | API | Fixture enum/runner, bukan integrasi Orders/Payments atau mass assignment semua total/status. | sebagian |
| T-24.a | PUT/GET singkat, UUID/finalize/versioning dan CORS allowlist | MediaUploadTest::test_real_put_finalize_replay_and_private_versioned_download; MediaUploadTest::test_bucket_inventory_permissions_cors_and_private_console | API | PUT 600s/GET 120s sesuai ADR-008; unsigned 403, origin asing tanpa Allow-Origin. | teruji |
| T-24.b | Expiry nyata, signed URL tidak di HTML/notifikasi/log/query | — | — | ⚑ Asersi X-Amz-Expires bukan menunggu expiry; leakage belum diuji. | tanpa tes |
| T-25.a | Enam bucket, public hanya published, private/console, versioning dan lifecycle | MediaUploadTest::test_bucket_inventory_permissions_cors_and_private_console; MediaUploadTest::test_public_media_is_private_until_validated_and_old_put_cannot_replace_published_bytes | API | Live MinIO bersyarat env; anonim tidak listing, staging tidak publik. | teruji |
| T-25.b | Key least privilege per service, TLS dan drift environment | MediaBackupTest::test_backup_reader_can_only_read_the_backed_up_buckets; gate compose | API + Gate | Backup reader read-only/no-host-port diuji; bukan semua key/TLS/drift staging. | sebagian |
| T-26.a | Env wajib dan image tanpa .env | gate compose | Gate | Render/env inventory bukan scan isi image. | sebagian |
| T-26.b | Secret manager/scanning, rotasi triwulan, akun proyek | — | — | ⚑ Belum bukti secret scan/inventaris rotasi atau kepemilikan akun. | tanpa tes |
| T-27.a | Lint/static analysis, locked installs, dependency audit dan build | gate rantai pasok | Gate | Workflow ada, bukan bukti keamanan semua paket atau deployment. | sebagian |
| T-27.b | SBOM, image scanning/provenance, registry privat, non-root/read-only, CD/smoke | — | — | ⚑ Belum verifikasi menyeluruh provenance/runtime hardening/rantai CD staging. | tanpa tes |
| T-28.a | Backup reader read-only dan relink checksum restore | MediaBackupTest::test_backup_reader_can_only_read_the_backed_up_buckets; MediaBackupTest::test_relink_points_media_at_the_restored_version_only_when_the_checksum_matches | API | Deny PUT/DELETE/exports, mismatch/missing tidak direlink. | teruji |
| T-28.b | Dump pra-deploy/list, offsite terenkripsi/PITR, RPO/RTO, retensi/kunci terpisah dan drill launch | — | — | ⚑ Skrip backup-pitr-drill/backup-object-drill tersedia, bukan hasil run staging pada register ini. | tanpa tes |
| T-29.a | Claim hash unik/aktif satu, matched_order_number, lifecycle/permission verifikasi dan flag procurement mati | — | — | Claim marketplace belum ada; entropi/throttle/void/notifikasi belum diuji. | belum diimplementasi |
| T-30.a | proof_files privat, buyer ref masked bukan credential, owner/audit unduh, retensi dan larangan notifikasi | — | — | Proof marketplace belum ada; media generik bukan bukti akses buyer/proof. | belum diimplementasi |
| T-31.a | Audit reason/request_id wajib dan mutasi access | AuthSchemaTest::test_audit_request_id_is_required_and_reason_cannot_be_empty; StaffRbacTest::test_permission_endpoints_audit_before_after_and_replay_without_duplicate_mutations | API | Bukan semua price/stock/status mutation. | sebagian |
| T-31.b | Append-only/reverses_id ledger, dual approval, role DB, migrasi tak tulis uang/status dan alert adjustment | — | — | Ledger bisnis/adjustment belum tersedia; tes penolakan UPDATE/DELETE/approved_by berbeda belum ada. | belum diimplementasi |
| T-32.a | Log JSON correlation, user-hash dan PII scrub | ObservabilityTest::test_log_processor_redacts_pii_and_hashes_user_ids; ObservabilityTest::test_stderr_channel_emits_one_json_object_per_line_with_correlation; ObservabilityTest::test_exception_in_log_context_is_normalised_and_redacted | API | Termasuk exception nested; bukan event Sentry aktual. | teruji |
| T-32.b | send_default_pii=false, Sentry scrub sebelum kirim, retensi/akses log+webhook dan presigned query | — | — | ⚑ Tes processor log tidak membuktikan payload Sentry/vendor/query presigned. | tanpa tes |
| T-33.a | Healthcheck/dependency/restart Compose dan pemulihan manual satu node | gate compose | Gate | Render bukan fault injection restart/restore; tidak ada klaim HA. | sebagian |
| T-33.b | Drill RPO/RTO, kapasitas disk, status page, trigger upgrade | — | — | ⚑ Belum bukti pemulihan node mati/offsite staging atau alert operasional. | tanpa tes |
| T-34.a | No-store cart dan admin client read | CartCheckoutTest::test_add_merge_update_remove_stock_and_repricing; apps/admin/tests/admin-api.test.ts — admin requests bootstrap CSRF, send JSON reasons and unique idempotency keys without retries | API + Admin | Header cart dan opsi mock, bukan seluruh SSR/API/edge. | sebagian |
| T-34.b | Cache key/Vary/CDN/ISR aman, private sessions/payments dan cache poisoning | scripts/check-edge-headers.py::check | Checker | Checker no-store HTML nonce, belum dua customer/CDN staging. | sebagian |
| T-35.a | Aset demo SVG orisinal sesuai manifest | DemoAssetProvenanceTest::test_ten_original_svgs_match_the_manifest | API | 10 SVG cocok, tanpa image/script; bukan pembuktian hukum seluruh provenance. | sebagian |
| T-35.b | Larangan menyalin KI/brand, observasi izin+hanya-baca, tanpa PII/credential sumber, robots dan Blueprint bukan klaim realitas | — | — | ⚑ Aturan proses §4 butuh review manusia; unit test SVG tidak cukup. | tanpa tes |
| T-36.a | Refund/settlement manual, stock return belum kirim, late-paid exception, dual approval/reason dan dashboard eskalasi | — | — | Payments/refund/expiry race belum dibangun (Q-7); enum bukan bukti bisnis. | belum diimplementasi |
| T-37.a | Outbox → indeks setelah commit | ProductSearchTest::test_product_update_reaches_the_index_through_outbox_within_sixty_seconds | API | Efek ES setelah commit; belum negatif sebelum commit/rollback atau crash setelah efek eksternal. | sebagian |
| T-37.b | Inbox dedup per consumer, rollback/retry | InboxConsumerTest::test_delivery_is_processed_once_per_consumer; InboxConsumerTest::test_failed_effects_roll_back_and_retry_safely | API | Efek fixture pertama saja; efek gagal rollback, retry aman. | teruji |
| T-37.c | Lease dispatch, backoff+jitter/max attempts/DLQ/timeout/circuit breaker | OutboxDispatchTest::test_dispatch_filters_before_limit_and_recovers_only_the_stale_lease; OutboxDispatchTest::test_discarding_queue_does_not_mark_event_delivered | API | Lease dan queue gagal ada; belum fault injection menyeluruh. | sebagian |
| T-37.d | Dedup charge/GRN/notifikasi event-channel-recipient dan replay DLQ | — | — | Payment/GRN/WA belum ada; inbox DB bukan exactly-once provider. | belum diimplementasi |
| T-38.a | Env terpisah, TLS/HSTS edge | gate compose; scripts/check-edge-headers.py::check | Checker | Render/checker tersedia; bukan deployment/domain nyata. | sebagian |
| T-38.b | Template WA approval, expiry register+owner, alert 30 hari dan akun proyek | — | — | ⚑ Belum pembuktian operasional domain/sertifikat/provider/template. | tanpa tes |
| T-39.a | Link sosial hanya email terverifikasi atau owner signed-in | StorefrontAuthTest::test_social_linking_requires_a_verified_email_or_a_signed_in_owner; AuthSecurityGatesTest::test_unverified_provider_email_returns_http_conflict_without_linking | API | HTTP 409, tidak ada identity/session; provider dimock. | teruji |
| T-39.b | Identity provider/user unik lintas akun | AuthSchemaTest::test_provider_identity_is_unique_across_users | API | Duplicate provider+ID ditolak 23505, provider lain diterima. | teruji |
| T-39.c | OAuth state, sign-in bukan link akun browser lain, verifikasi sebelum checkout dan notifikasi link | StorefrontAuthTest::test_social_redirect_carries_state_and_callback_rejects_a_forged_state; StorefrontAuthTest::test_social_sign_in_on_a_shared_browser_switches_account_instead_of_linking | API | State palsu dan pergantian akun diuji; bukan live Google/X atau notifikasi/email-verified checkout. | sebagian |
| T-40.a | Bypass is_admin hanya guard admin, CHECK operator dan audit reason/request_id | StaffRbacTest::test_admin_bypass_is_audited_once_with_request_id_but_never_on_web; AuthSchemaTest::test_admin_requires_operator | API | Audit sekali dan counter; bukan review akses operasional. | teruji |
| T-40.b | Flag manual bukan role/seeder/UI, alert/review triwulan dan revoke insiden | — | — | ⚑ Counter bukan alert/review atau drill pencabutan. | tanpa tes |
| T-41.a | Grant/revoke langsung berlaku tanpa restart | StaffRbacTest::test_direct_grants_and_revocation_take_effect_in_the_same_session | API | Request berikutnya 403 dalam sesi sama. | teruji |
| T-41.b | Larangan UPDATE pivot, TTL cache, runbook darurat invalidasi | — | — | ⚑ Tidak ada lint/drill menyeluruh; tidak mengizinkan edit pivot langsung. | tanpa tes |
| T-42.a | Audit access/settings bukan activity_log | StaffRbacTest::test_permission_endpoints_audit_before_after_and_replay_without_duplicate_mutations; AuthSchemaTest::test_audit_request_id_is_required_and_reason_cannot_be_empty; AdminFlagIdempotencyTest::test_audit_preserves_the_request_id_seen_by_the_client | API | before/after/reason/request_id terbukti sebagian aksi, bukan void/force/mark-paid/adjustment. | sebagian |
| T-42.b | Append-only audit, larangan mengganti AuditThis dan arsip immutable | — | — | ⚑ Belum tes UPDATE/DELETE audit ditolak atau semua aksi+arsip tak bisa diubah. | tanpa tes |
| S-1 | §1 TB-5/7/8: datastore internal tanpa port host | gate compose | Gate | CI assert no-host-port; bukan scan firewall staging. | sebagian |
| S-2 | §1 TB-3/§3: Sanctum dua guard, cookie/CSRF/2FA | AuthSecurityGatesTest::test_totp_authenticates_the_guard_and_rotates_session_and_admin_cookie_cannot_authenticate_web; AdminLoginTest::test_login_enforces_csrf_password_and_operator_status | API | Lihat T-02/03; ADR-007 D-6a dibuka kembali 2026-11-22, subdomain dipilih dan kontrol disetujui ulang 2026-11-22; implementasi/tes dua host pending PR #45. | sebagian |
| S-3 | §1 TB-6: satu penulis tabel, boundary dan ID-only events | gate arsitektur | Gate | Deptrac membatasi dependency, bukan perilaku B1–B6/SQL/semantik payload menyeluruh. | sebagian |
| S-4 | §1 TB-11: outbound provider retry+jitter/circuit breaker | — | — | Tidak ada bukti lintas payment/kurir/WA; ongkir saja tidak cukup. | belum diimplementasi |
| S-5 | §3 X-Actor-Type diisi server dari guard | — | — | ⚑ Belum ditemukan asersi khusus anti-spoofing X-Actor-Type. | tanpa tes |
| S-6 | §4 tujuh batas KI/kredensial/observasi | DemoAssetProvenanceTest::test_ten_original_svgs_match_the_manifest | API | Hanya manifest aset; tujuh aturan tetap review manusia (T-35/26). | sebagian |
| S-7 | §6 sign-off pemilik, konfirmasi klien, Q-2 | docs/threat-model.md §6: Fatharoni, chat bot Product Engineer 2026-11-22 | Review manusia | ⚑ Sign-off bentuk satu host dan penerimaan retroaktif urutan Task 19 tetap historis; pemilik memilih subdomain admin 2026-11-22, re-approval kontrol yang diamendemen tercatat 2026-11-22; implementasi pending PR #45. Persetujuan ulang dicatat, bukan bukti tes atau implementasi. | tanpa tes |
| S-8 | §7 register dan artefak ZAP/drill/load/bypass | gate dokumen | Gate | Auditor docs bukan runtime; ZAP/drill/load/bypass staging belum tercatat. | sebagian |

## 4. Ringkasan dan gap

**Sign-off diperbarui 2026-11-22:** pemilik menyetujui ulang threat model subdomain admin terpisah dengan persetujuan klien, disampaikan Product Engineer. Cakupan: cookie sesi dan CSRF admin host-only, pemeriksaan origin admin tepat, CORS per host dan tes isolasi storefront/admin; 2FA serta guard terpisah tidak berubah. Persetujuan bukan bukti kontrol sudah dibangun: P0.10 tetap tidak dicentang, implementasi masih dikerjakan di PR #45.

Hasil hitung baris §3: **98 kontrol/paket** — **25 teruji**, **36 sebagian**, **20 tanpa tes**, **17 belum diimplementasi**. Bukan jumlah ancaman tertutup; satu T-n memiliki status campuran. Delapan baris S mengulang lingkup §, bukan delapan fitur baru.

1. Orders/Payments/webhook/refund/ledger, PoBatch/enrollment, UGC, quotation, claim/proof marketplace, GRN dan WA belum dibuktikan end-to-end. Fixture runner/inbox/kuota tidak menggantikan implementasi. Gate 1.000 checkout/500 enrollment dan race expiry/payment belum tersedia.
2. Matriks CSRF route×guard×origin, SSR tamu, larangan token/secret bundle, semua aksi berisiko menghasilkan audit, append-only DB/arsip dan role DB terpisah belum terbukti menyeluruh.
3. Subdomain admin produksi dipilih pemilik 2026-11-22; ADR-007 D-6a/TB-3 diperbarui, re-approval tercatat 2026-11-22; implementasi pending PR #45. Nginx memetakan frontend per host tetapi route API/bootstrap belum dibatasi Host; Sanctum stateful gabungan bukan admin-only Origin/Referer check. CORS default wildcard+supports_credentials=false bukan kebijakan per surface (T-02.e/T-03.e–g).
4. WAF/Waiting Room/bypass, cache poisoning antar pelanggan, kapasitas/isolasi/TLS staging, k6/Playwright dan checker header runtime belum memiliki artefak run pada register ini.
5. PII scrub log bukan pembuktian Sentry/provider/query presigned. Secret scanning/rotasi/expiry, retensi, SBOM/provenance/runtime hardening, approval template WA serta review KI/observasi tetap gap.
6. Reader/relink backup bukan restore offsite PostgreSQL+object/PITR terintegrasi sebelum launch. Skrip drill bukan hasil RPO/RTO staging.
7. Sign-off Fatharoni 2026-11-22 dan penerimaan retroaktif urutan Task 19 (disampaikan Product Engineer tanggal sama) hanya bentuk satu host. Ia tetap bukti historis, bukan bukti teknis atau persetujuan amandemen subdomain; re-approval pemilik dengan persetujuan klien tercatat 2026-11-22; implementasi pending PR #45.

**Verdict P0.10: tetap parsial/tidak dicentang, menunggu implementasi kontrol subdomain yang sudah disetujui ulang.** Register dan interim ADR-008 tersedia; gateway/kurir/WA/brand/PO cukup dilacak. Keputusan host sudah dijawab: subdomain admin terpisah (pemilik 2026-11-22). ADR-007 D-6a kini dibuka kembali; sign-off/penerimaan retroaktif bentuk satu host tidak menyetujui otomatis host-only cookie/XSRF dan admin-only Origin/CSRF/CORS hasil amandemen. Penegakan Host/origin/CORS dan tes dua host masih gap teknis eksplisit; re-approval tidak boleh diklaim sebagai bukti implementasi atau penutupan Phase 0.

Pemilik tindak lanjut: pemilik modul untuk tes fitur, penanggung jawab rilis untuk gate/artefak lingkungan, pemilik proyek/klien untuk keputusan eksternal dan topologi. Tambahkan tanggal/perintah/artefak run nyata saat tersedia.
