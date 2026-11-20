# Dokumen 06 — Kebutuhan Admin Dashboard (diturunkan dari sisi customer)

> **Catatan status (ADR-003 K-4 · 2026-11-17, direvisi 2026-11-18).** Kebutuhan di dokumen ini **diturunkan dari janji ke sisi customer** (Doc 05/07) — bagian itu **tetap milik kami dan tidak berubah** karena rujukan arsitektur berganti. Yang ikut berubah adalah **kerangka yang dipakai Blueprint**: **model role menjadi 10 role (Doc 11 B-10)** dan **grup menu diselaraskan dengan menu tree Blueprint §4**. Sejak keputusan pemilik 2026-11-18, kerangka identitas/auth/RBAC mengikuti register mengikat **Doc 11 §4.4** (butir A–J): satu tabel `users` dengan `is_operator`/`is_admin`, RBAC granular per permission lewat spatie, dan cookie sesi Sanctum. Alasannya ada di ADR-012 (RBAC/identitas/dependensi); mekanisme sesi di ADR-007 (BFF/sesi) hasil amandemen. Fakta observasi tetap; keputusan arsitektur bersumber dari Blueprint dan direkonsiliasi di **Doc 11**.

Tanggal: 2026-11-16 · direvisi 2026-11-17 mengikuti Blueprint · direvisi 2026-11-18 mengikuti keputusan pemilik (Doc 11 §4.4)
Sumber penurunan: setiap kemampuan customer di `05-customer-feature-inventory.md` punya "beban kerja" di belakang layar. Admin dashboard = tempat beban itu diselesaikan. Ini **spesifikasi kebutuhan kami**, bukan tiruan panel siapa pun.

Batas yang berubah setelah REA pass (Doc 08 §3): dari sisi publik **bisa** dipastikan bahwa panel admin situs sumber bukan aplikasi terpisah — rute `/admin` berada di aplikasi yang sama, memakai layout global yang sama, dan memakai komponen chunk yang sama dengan halaman tracking/invoice customer (satu tabel data generik). Yang **tetap tidak bisa** diamati adalah daftar layar, field, dan alur kerja di dalamnya; karena itu dokumen ini diturunkan dari sisi customer, bukan dari observasi admin. Tidak ada upaya enumerasi sub-rute admin yang dilakukan.

## 1. Role & hak akses (RBAC granular per permission — Blueprint §4; Doc 11 §4.4 B)

Model role mengikuti **10 role Blueprint** (resolusi Doc 11 **B-10**), tetapi sejak keputusan pemilik 2026-11-18 hak akses **granular per aksi**, bukan per role semata: permission `<modul>.<aksi>` lewat `spatie/laravel-permission` (guard `admin`), diberikan lewat role (bundel) **atau langsung ke user** (`model_has_permissions`). Pivot lama `role_user`/`permission_role` **diganti** bentuk paket (Doc 11 §4.4 B). Otorisasi tetap ditegakkan **di sisi server (Laravel policy/gate)**; menyembunyikan menu di Next.js **bukan** otorisasi (Doc 04 §9, Doc 01 §9). Kolom kanan tabel role di bawah adalah **alasan yang kita turunkan dari sisi customer** — kebutuhan ini tetap milik kita, bukan Blueprint. Rincian mekanisme ada di §1.1–§1.8.

| Role Blueprint | Cakupan (menu Blueprint §4) | Alasan dari sisi customer (dipertahankan) |
|---|---|---|
| **superadmin** | bundel penuh permission admin + System (staff, roles/permissions, settings, webhooks, audit logs, jobs, feature flags) + setujui kebijakan; bypass BUKAN dari role ini (§1.6) | pengaturan bisnis, toggle login sosial, maintenance mode |
| **catalog-manager** | Catalog (products, variants/SKU, categories, brands, collections/drops, pricing, media) | PDP menampilkan varian 2 sumbu, harga, media, kategori/fandom |
| **sales-ops** | Sales (all orders, ready-to-ship, returns, cancellations) | segmentasi jalur RTS di halaman Pesanan & Retur |
| **preorder-ops** | Pre-Order (campaigns/batches, batch products, participants, closing, consolidation, cost allocation) | PDP periode batch + kuota; batch advance per-buyer (F5) |
| **sourcing-ops** | Request/Sourcing (incoming, sourcing, quotations, approvals, quote-to-order) + moderasi lampiran request | antrian request + penawaran berversi + SLA |
| **warehouse-staff** | Inventory (warehouses, stock, reservations, movements, transfers, adjustments, stocktake) + Fulfillment (picking, packing, labels, shipments, tracking, exceptions) | timeline status barang butuh event gudang/pick/pack nyata |
| **procurement-staff** | Procurement supplier (suppliers, purchase requisitions, purchase orders, supplier invoices, goods receipts) + International Logistics (forwarders, consolidations, cargo, customs, landed costs, milestones) | leg impor & landed cost = dasar tagihan pajak/pos (Doc 05 §5.5, F3) |
| **finance** | Billing & Finance (invoices, DP/installments, additional charges, payments, refunds, receivables, cash/bank) | "cek jatuh tempo", tipe invoice terpisah, rekonsiliasi gateway |
| **customer-support** | CRM & Support (customers, addresses, tickets, disputes, notifications) + 360° profil | SLA jawaban komplain, retur, laporan bayar ganda (F11) |
| **analyst (read-only)** | Reports + dashboard KPI (hanya-baca) | margin, batch profitability, stock aging, cohorts |

**Pemetaan tanggung jawab lama → 10 role Blueprint.** Sebelum Blueprint ada **dua inventaris yang saling tidak konsisten** (fakta ini tercatat di Doc 11 B-10): tabel ini sendiri dulu menabulasi **8 baris tanggung jawab** (Owner, Merchandiser, Warehouse, CS, Finance, Editor konten, Moderator, dev/ops), sementara `roles` yang di-seed di Doc 10 §8 hanya **5** (Admin, Ops, Warehouse, Finance, Support). Yang berubah sekarang: **sepuluh role Blueprint menjadi satu-satunya set yang di-seed**, dan kedua inventaris lama dipetakan ke atasnya.

| Tanggung jawab lama | → Role Blueprint | Catatan |
|---|---|---|
| Owner | superadmin | penuh + grup System |
| Merchandiser | catalog-manager + preorder-ops | harga/varian/media vs kuota/batch adalah dua orang berbeda di Blueprint |
| Warehouse | warehouse-staff | + Fulfillment (Blueprint menggabungkan gudang & pengiriman terakhir) |
| CS | customer-support | refund hanya **ajukan**; eksekusi di finance (pemisahan tetap) |
| Finance | finance | + receivables |
| dev/ops | superadmin (grup System) | feature flag, kredensial, job — bukan role terpisah di Blueprint |
| **Editor konten** | **(tidak ada padanan)** | lihat di bawah |
| **Moderator** | **(tidak ada padanan)** | lihat di bawah |

Role lama `Ops` (makro) terpecah menjadi **sales-ops / preorder-ops / sourcing-ops**; `procurement-staff` dan `analyst` tidak punya padanan lama karena keduanya pekerjaan yang baru disebut Blueprint §4.

**Celah yang Blueprint tinggalkan (B-10).** Dua tanggung jawab khas kita tidak punya role dalam sepuluh role Blueprint: **Editor konten** (halaman kebijakan ber-versi, FAQ, banner, nav, SEO — grup "Marketing & CMS") dan **Moderator** (ulasan + lampiran user — "Reviews" di grup yang sama). Keputusan teknis yang berlaku: keduanya diwujudkan sebagai **kumpulan permission per `<modul>.<aksi>` di bawah role yang ada** — CMS → `catalog-manager`, moderasi UGC → `customer-support` (ulasan) dan `sourcing-ops` (lampiran request) — **bukan sebagai role ke-11**, karena menambah role berarti menyimpang dari §4 tanpa catatan. Sejak 2026-11-18 mekanisme ini punya bentuk resmi: permission CMS/moderasi bisa di-grant **langsung ke orangnya** lewat `model_has_permissions` (§1.4), tanpa mengubah bundel role yang sudah di-seed. **Siapa yang benar-benar memegang permission itu adalah keputusan organisasi klien → Doc 11 §6 Q-8.**

Aturan yang tetap wajib: `audit_logs` setiap mutasi berisiko (harga, status, refund, publish halaman kebijakan) dengan `reason` + `before`/`after` + `request_id`, append-only (S-6), dan pemisahan "ajukan vs setujui" untuk refund di atas ambang tertentu. Yang berubah per 2026-11-18: **2FA kini wajib untuk semua login admin** (gerbang `is_operator`, bukan lagi "role non-owner"), dan bypass hanya lewat flag `is_admin` yang berjejak audit — lihat §1.1 dan §1.6.

### 1.1 Identitas tunggal dan gerbang login admin

Satu tabel `users` melayani admin dashboard dan storefront (Doc 11 §4.4 A; ADR-012 (RBAC/identitas/dependensi)). Kolom populasi `kind` yang lama **dihapus**, diganti dua boolean ortogonal:

| Kolom | Aturan |
|---|---|
| `is_operator boolean NOT NULL DEFAULT false` | satu-satunya gerbang boleh login ke admin dashboard (selain `status = active` + 2FA terkonfirmasi) |
| `is_admin boolean NOT NULL DEFAULT false` | menyalakan bypass RBAC di admin (§1.6); tidak memberi hak apa pun di storefront |

- `CHECK (is_admin = false OR is_operator = true)`: "bypass tanpa hak login admin" adalah keadaan mustahil di tingkat database, bukan kemungkinan yang dijaga kode.
- Indeks lama `users_kind_status` **diganti** `users_operator_status (is_operator, status)` (Doc 09 §2).
- **Login admin = email + password saja, plus 2FA wajib.** Tidak ada tombol Google/X di halaman login admin dan tidak akan pernah ada: jalur sosial ke admin ditutup by design (ADR-012 (RBAC/identitas/dependensi) D-4). 2FA memakai `staff_2fa_secrets` dengan **10 recovery code ter-hash**, satu kode satu kali pakai (Doc 09 §2). Kehilangan perangkat sekaligus semua recovery code berarti pemulihan lewat prosedur operasional berjejak, bukan lewat login sosial.
- **Login storefront = akun `active` apa pun, termasuk operator.** `is_operator`/`is_admin` **tidak memberi hak apa pun di web**: otorisasi storefront adalah kepemilikan (policy owner) + status akun. Operator yang berbelanja di storefront adalah aktor `web` (§1.8).
- Customer biasa (`is_operator = false`) tidak punya jalur ke admin. Sesi storefront juga tidak pernah menjadi sesi admin: meski akun itu operator, masuk admin wajib login terpisah di guard `admin` dengan email+password+2FA (ADR-007 (BFF/sesi) D-4a).
- Tabel `staff_users` **tidak dibuat** (Doc 11 §4.3): satu orang satu baris `users`; atribut operator (2FA) punya tabel sendiri. Dua tabel untuk satu orang adalah sumber bug "staf yang juga customer".

### 1.2 Bentuk RBAC: spatie, granular per aksi

- Paket `spatie/laravel-permission` (versi terkunci di Doc 11 §4.4 D). Bentuk tabel mengikuti paket supaya tidak perlu fork: `roles`/`permissions` memakai **`name` + `guard_name`** (kolom `code` yang lama **diganti** `name`; `roles.name` berisi kode role Blueprint seperti `catalog-manager`, `permissions.name` berisi `<modul>.<aksi>`), unik pada `(name, guard_name)`. Kolom ekstra kita tetap hidup: `roles.description`, `roles.is_system`, `permissions.group_name`, `permissions.description`.
- Pivot: `model_has_roles`, `model_has_permissions`, `role_has_permissions` — kolom morph standar paket (`model_type`, `model_id`), bukan disederhanakan menjadi `user_id` saja karena paket membaca kolom morph. Scope pindah ke tabel sendiri `role_scopes` (§1.5).
- `guard_name` seluruh RBAC admin adalah **`admin`**, bukan default `web`: storefront tidak memakai spatie (tidak ada role/permission customer; menambahkannya berarti dua sumber otorisasi untuk satu orang).
- Aksi standar **terpisah**: `view` (index/show), `create`, `update`, `delete`. Aksi khusus hanya yang terdaftar di Doc 11 §4.4 B: `billing.void`, `order.force_transition`, `batch.close`, `payment.mark_paid_manual`. Menambah aksi khusus berarti mengubah register itu, bukan diam-diam di seeder.
- Prefix `<modul>` adalah kata benda domain tunggal kecil, kanonik di Doc 11 §4.4 B. Disalin apa adanya ke sini supaya Doc 06 dan `openapi.yaml` tidak bisa berbeda:

| Modul (Doc 09 §19) | Prefix | Modul | Prefix |
|---|---|---|---|
| Identity | `user` | Logistics | `logistics` |
| Catalog | `catalog` | Promotion | `promotion` |
| Content | `content` | Sourcing | `sourcing` |
| PreOrder | `batch` | Procurement | `procurement` |
| Billing | `billing` | MarketplacePurchase | `marketplace` |
| Orders | `order` | UGC | `review` |
| Inventory | `inventory` | Settings | `setting` |
| Cart | `cart` | Notification | `notification` |
| Payments | `payment` | Support | `ticket` |
| Fulfillment | `shipment` | Audit | `audit` |
| Reporting | `report` | Search | — (tanpa tabel, tanpa permission admin) |

`Lintas modul` (outbox/inbox/idempotency) tidak punya permission admin: ia infrastruktur, bukan layar. Jumlah tabel dan kepemilikannya tidak ditulis tangan di sini — pemiliknya Doc 09 §19 (Doc 11 §4.4 G).

### 1.3 Matriks per permission per area admin

Matriks ini adalah **kebutuhan kami** yang diturunkan dari janji customer (§4) dan cakupan menu Blueprint §4, dengan prefix kanonik §1.2. Kolom view/create/update/delete menyatakan aksi mana yang **ada** untuk area itu; "tidak ada" berarti aksi itu memang tidak boleh ada (bukan belum dirancang). Kolom terakhir adalah role pembundel utama dari 10 role Blueprint; kebutuhan di luar bundel ditutup lewat grant langsung (§1.4), bukan role ke-11.

| Area admin (grup menu §2.2) | Prefix | view | create | update | delete | Aksi khusus | Role pembundel utama |
|---|---|---|---|---|---|---|---|
| Dashboard | `order`, `payment`, `report` | ada (proyeksi read-only) | — | — | — | — | semua role sebatas hak view modul sumber; `analyst` hanya view |
| Catalog | `catalog` | ada | ada | ada | ada | — | `catalog-manager`; role lain cukup view lewat grant langsung |
| Sales | `order` | ada | ada (order atas nama buyer — keputusan kita, bukan observasi) | ada (transisi beralasan) | tidak ada (batal = transisi + reason, baris tidak dihapus) | `order.force_transition` | `sales-ops`; `customer-support` view + ajukan retur; `finance` view |
| Pre-Order | `batch` | ada | ada | ada | ada untuk draft; batch hidup dibatalkan, bukan dihapus | `batch.close` | `preorder-ops`; `catalog-manager` view; `finance` view posisi bayar |
| Request/Sourcing | `sourcing` | ada | ada | ada (approve = grant terpisah, lihat catatan) | ada untuk draft | — | `sourcing-ops`; `customer-support` view |
| Procurement (supplier) | `procurement` | ada | ada | ada | ada untuk draft | — | `procurement-staff`; `finance` view supplier invoice |
| Inventory | `inventory` | ada | ada (movement/adjustment beralasan) | ada | terbatas draft; ledger pergerakan append-only, tidak dihapus | — | `warehouse-staff`; `catalog-manager` view; `sales-ops` view |
| International Logistics | `logistics` | ada | ada | ada | ada untuk draft | — | `procurement-staff`; `finance` view landed cost; `warehouse-staff` view |
| Fulfillment | `shipment` | ada | ada | ada (koreksi resi, exception) | terbatas draft label | — | `warehouse-staff`; `sales-ops` view/update resi; `customer-support` view |
| Billing & Finance | `billing`, `payment` | ada | ada (tagih manual, intent, refund) | ada (koreksi beralasan) | tidak ada (invoice di-void, payment di-refund; baris tidak dihapus) | `billing.void`, `payment.mark_paid_manual` | `finance`; `customer-support` view + ajukan refund (grant langsung); `sales-ops` view |
| Marketing & CMS | `content`, `promotion`, `review` | ada | ada untuk content/promotion; `review.create` tidak ada (ulasan lahir dari customer terverifikasi, Doc 05 §3 P-4) | ada (moderasi review = update) | ada untuk content/promotion/review (pelanggaran) | — | `catalog-manager` (content+promotion); `customer-support` (moderasi review); `superadmin` |
| CRM & Support | `user`, `ticket`, `notification` | ada | ada (buat/import akun, tiket, template notif) | ada (blokir, perpanjang jatuh tempo, preferensi) | `user.delete` tidak ada (hak hapus = status `deletion_requested` + legal-hold, §3.10); ticket/notification ada | — | `customer-support`; `superadmin` buat akun; `finance` view akun |
| Reports | `report` | ada | — | — | — | — | `analyst` (read-only); `finance`; `superadmin`; role lain sebatas bundel |
| System | `user`, `setting`, `audit` | ada | ada (setting/flag) | ada (grant `is_operator`/`is_admin` = update ber-audit) | terbatas untuk setting; `audit` tidak ada (append-only) | — | `superadmin`; `audit.view` bisa di-grant langsung ke auditor |
| MarketplacePurchase (opsional, §2; bukan grup Blueprint §4) | `marketplace` | ada | ada (terbitkan kode) | ada (verifikasi, tautkan ke shipment) | tidak ada (revoked = transisi, bukan hapus) | — | `finance` + `customer-support` (verifikasi beralasan, §3.6); `superadmin` |

Catatan yang mengikat cara membaca matriks:

- **"tidak ada" pada delete bukan kelalaian.** Objek beraudit/append-only (order, invoice, payment, ledger stok, audit) tidak pernah dihapus barisnya; pembatalan/void/refund adalah transisi atau aksi khusus dengan `reason`. Ini menjaga S-6 dan rekonsiliasi keuangan.
- **Modul Cart (`cart`) punya prefix kanonik tetapi tidak punya grup menu admin tersendiri di Blueprint §4.** Dukungan cart customer (mis. troubleshooting keranjang) dilayani lewat grant langsung `cart.view` di bawah CRM & Support, bukan layar baru. Jangan menambah grup menu untuknya tanpa catatan deviasi.
- **Pemisahan "ajukan vs setujui"** (refund, approve quote) diwujudkan lewat grant langsung + policy, bukan aksi khusus baru: register aksi khusus terkunci di Doc 11 §4.4 B. Contoh: CS memegang `payment.view` + hak mengajukan, `finance` memegang hak eksekusi; approver ≠ requester.
- Bundel role di-seed di Doc 10 §8. Setiap operasi admin di `openapi.yaml` wajib menyatakan `x-elzara-permission: <modul>.<aksi>` (Doc 11 §4.4 I; Doc 12 §3.1) supaya matriks ini dan spec tidak bisa berbeda tanpa terdeteksi. Operasi yang boleh diakses lewat bypass `is_admin` tetap menulis `audit_logs` (§1.6).

### 1.4 Pemberian langsung ke user (contoh kerja: view-only)

Tuntutan pemilik (2026-11-18): **seorang user admin dashboard boleh punya permission melihat index, tetapi TIDAK punya permission delete, edit, atau create.** Ini kemampuan kelas satu yang harus bisa diwakili satu baris data, bukan workaround dan bukan alasan membuat role baru per kombinasi aksi (ADR-012 (RBAC/identitas/dependensi) D-3). Jalurnya: `model_has_permissions` (guard `admin`).

Contoh kerja — operator view-only katalog:

1. Akun operator (ilustrasi: `rina@elzara.test`) ber-`is_operator = true`, `is_admin = false`, status `active`, 2FA terkonfirmasi. Ia tidak memegang role yang memuat `catalog.create/update/delete`.
2. Satu baris `model_has_permissions` (model User miliknya, permission `catalog.view`) memberinya hak index/show katalog. Tidak ada baris `catalog.create`, `catalog.update`, atau `catalog.delete` untuknya.
3. Hasil yang wajib dibuktikan test: GET daftar/detail produk → 200; POST/PATCH/DELETE produk → **403 dari server**, meski tombolnya sudah disembunyikan UI. Menyembunyikan tombol bukan otorisasi (§1.7).
4. Bila ia juga perlu melihat stok untuk menjawab pertanyaan customer, tambahkan grant langsung `inventory.view` — tanpa role baru dan tanpa memberinya hak tulis stok.
5. Mencabut hak: hapus baris lewat jalur paket (API spatie / `PermissionRegistrar`) supaya cache permission diinvalidasi (§1.7). `UPDATE`/`DELETE` mentah ke tabel meninggalkan cache basi = hak yang sudah dicabut masih hidup sampai cache kedaluwarsa.

Role adalah **bundel**, bukan satu-satunya jalur pemberian hak. `warehouse-staff` misalnya membundel hak inventory + fulfillment; kebutuhan "warehouse lead juga boleh melihat katalog" cukup satu grant `catalog.view` ke orang itu, bukan role ke-11 dan bukan mengubah bundel semua `warehouse-staff`.

### 1.5 Scope baris: `role_scopes`

Permission menjawab "boleh aksi apa"; tabel `role_scopes (user_id, role_id, scope_type, scope_id)` menjawab "baris mana yang boleh dilihat/diubah". `scope_type NULL = global | batch | category | warehouse` (Doc 09 §2; Doc 11 §4.4 B). Contoh: operator `preorder-ops` dengan scope `batch` id 12 hanya melihat batch 12 dan turunannya; punya `batch.view` tidak dengan sendirinya berarti semua batch. Policy wajib memeriksa keduanya: permission dulu, lalu scope baris. Satu pasangan user-role boleh punya scope global dan beberapa scope berbaris (PK komposit dengan aturan COALESCE yang lama dipertahankan di tabel baru). Scope menambah satu join pada setiap pemeriksaan hak berscope — itu biaya yang diterima jujur (ADR-012 (RBAC/identitas/dependensi), konsekuensi).

### 1.6 Bypass `is_admin`: risiko yang diterima, wajib berjejak

`is_admin = true` menyalakan `Gate::before` yang meloloskan semua permission admin. Aturan yang tidak bisa ditawar:

- **Setiap pemakaian bypass wajib menulis `audit_logs`**: `action = rbac.bypass`, `reason` (mengapa aksi ini dilakukan), dan `request_id`. Tanpa baris itu, aksi berisiko dianggap tidak terjadi (S-6; Doc 11 §4.4 F). `activity_log` spatie tidak memenuhi kewajiban ini.
- Bypass **hanya di guard `admin`**, tidak pernah di guard `web`: operator yang berbelanja tetap tunduk pada owner policy seperti customer lain.
- Role `superadmin` **tidak lagi berarti bypass** — ia bundel permission seperti sembilan role lain. Mencabut bypass = mengubah flag `is_admin` satu orang (update ber-audit), bukan mengedit role semua pemegangnya.
- Ini **risiko yang diterima pemilik secara sadar** (ADR-012 (RBAC/identitas/dependensi) D-2): mitigasinya jejak audit per pemakaian + 2FA wajib + CHECK database, bukan penghapusan fitur. Konsekuensi jujur: satu baris audit ekstra per request admin yang memakai bypass, dan pemegang `is_admin` adalah target phishing paling berharga — review berkala daftar pemegangnya wajib masuk runbook operasional.

### 1.7 Menu hanya kosmetik; cache permission wajib diinvalidasi

- Filter menu di Next.js hanya kenyamanan UI. Otorisasi hidup di **Laravel policy/gate per request** (Doc 04 §9; Doc 01 §9). Test wajib: user tanpa hak memanggil endpoint admin → 403 dari server, dibuktikan di feature suite, bukan diperiksa manual. Situs sumber menjaga `/admin` di klien (shell 200 untuk tamu, Doc 08 §3) — pola itu tidak kita tiru.
- spatie menyimpan **cache permission**. Pencabutan/penambahan hak wajib lewat jalur yang meng-invalidasi cache (`PermissionRegistrar`), bukan `UPDATE` mentah ke `model_has_permissions`/`role_has_permissions`. Ini fakta operasional yang masuk runbook (Doc 11 §4.4 B), bukan catatan kaki skema: grant yang dicabut tanpa invalidasi cache masih dilayani sampai cache kedaluwarsa.

### 1.8 Transport auth: cookie sesi Sanctum, bukan token

Mekanisme sesi **diputuskan pemilik 2026-11-18** (ADR-007 (BFF/sesi), amandemen; Doc 11 §4.4 C) — bukan lagi pertanyaan terbuka:

- `laravel/sanctum` dengan `statefulApi`: cookie sesi **same-site** karena edge satu host dengan pemisah path (`/api/`, `/admin`, `/`). Tidak ada CORS lintas origin dan tidak ada `SameSite=None`.
- Dua sesi terpisah: cookie `elzara_web_session` (guard `web`) dan `elzara_admin_session` (guard `admin`). Keduanya `Secure; HttpOnly; SameSite=Lax; Path=/`. **`Path=/` wajib** supaya cookie ikut terkirim ke `/api/`; pemisahan admin/storefront dilakukan lewat **nama cookie + guard**, bukan lewat path. Cookie storefront tidak pernah diterima guard admin, sehingga XSS di storefront tidak menjadi akses admin.
- **CSRF wajib pada setiap mutasi**, termasuk admin. Cookie `XSRF-TOKEN` sengaja tidak HttpOnly karena dibaca JavaScript untuk header `X-XSRF-TOKEN`; endpoint `sanctum/csrf-cookie` dipanggil sebelum mutasi pertama. Itu bagian dari desain Sanctum, bukan kelalaian.
- `personal_access_tokens` **tidak dibuat**; tidak ada bearer token di browser (ADR-007 (BFF/sesi) D-3a). Kredensial server-to-server (webhook gateway, worker internal) memakai tanda tangan/HMAC atau jaringan internal, bukan cookie sesi.
- Header log `X-Actor-Type` berubah dari `customer | staff | system` (lama) menjadi **`web | admin | system`**, diisi server dari guard/sesi yang dipakai, **tidak pernah dipercaya dari klien** (Doc 11 §4.4 I). Operator yang berbelanja di storefront adalah aktor `web`.
- Pemilik proyek dan klien dilaporkan menyetujui kontrol cookie sesi, CSRF, 2FA, dan pemisahan guard pada 2026-11-20. Lokasi artefak tertulis masih ditunggu (ADR-007 (BFF/sesi) D-6a; `docs/threat-model.md` §6). Q-2 menyempit menjadi "apakah klien punya IdP/SSO sendiri"; kalau ya, provider OIDC ditambahkan pada jalur login yang sama, bukan mengganti mekanisme sesi.

## 2. Kerangka screen (nav admin)

```
Ringkasan hari ini        | pesanan baru, uang masuk, tunggakan jatuh tempo, paket perlu resi
Katalog                   | produk · varian/matriks · media · kuota batch · harga
Batch & Pre-order         | daftar batch · buka/tutup · ETA ·GRN · posisi bayar · pembatalan
Request / Penawaran       | antrian pengajuan · buat penawaran · tolak · SLA · riwayat per akun
Penjualan (Orders)        | daftar · detail · koreksi status · pembatalan · retur/refund
Penagihan (Billing)       | invoice per tipe · jatuh tempo · reminder · rekonsiliasi gateway
Pengiriman                | metode & tarif per tier paket · label · input resi massal · exception kurir
MarketplacePurchase (opsional; dahulu "Procurement")    | pembelian pihak ketiga atas nama buyer · status kode · bukti bayar · penautan ke order
Promosi                   | voucher · kampanye · poin · drop/klaim terbatas
Konten                    | halaman kebijakan (versi) · FAQ · banner · nav · SEO · template email
Ulasan & Media            | moderasi ulasan · moderasi lampiran · alt text
Customer                  | akun · sesi/perangkat · favorit · alamat · preferensi notifikasi
Laporan                   | penjualan, margin, umur piutang pelunasan, stok menipis, funnel checkout
Pengaturan                | brand & tema · bahasa · payment/shipping/WA driver · fitur flag · legal
```
⚠️ baris "MarketplacePurchase" (dahulu bernama "Procurement") muncul **karena** sisi customer memperlihatkan riwayat kode checkout dari marketplace lain dan jalur "beli atas nama"; kalau model bisnis klien Anda tidak memakai jalur itu, module ini di-nonaktif-kan lewat flag — tapi jangan dihapus dari desain, karena ia mengubah flow pembayaran & tracking.

### 2.1 Grup menu yang ditambah/diubah mengikuti Blueprint §4

Pohon nav di atas kami samakan dengan menu tree Blueprint §4. Yang berubah (tidak ada requirement hasil janji customer yang dihapus — hanya **nama, pemisahan grup, dan penambahan**):

- **✚ International Logistics** — baru per Blueprint (Doc 11 **B-3** + butir **R-14**): Forwarders · Consolidations · Cargo · Customs · Landed Costs · Milestones. Menopang tagihan pajak/pos & landed cost yang dijanjikan ke customer (Doc 05 §5.5, F3), kini sebagai entitas tersendiri, bukan catatan manual.
- **✚ Procurement (supplier)** — baru per Blueprint (**B-4**): Suppliers · Purchase Requisitions · Purchase Orders · Supplier Invoices · Goods Receipts.
- **⚠️ Rename (B-4):** baris "Procurement (opsional)" di pohon atas DAN layar §3.6 **bukan** procurement-supplier Blueprint — ia jalur **beli-atas-nama di marketplace pihak ketiga** (temuan customer). Sesuai Doc 11 **B-4** ia diganti nama menjadi **`MarketplacePurchase`** (kode, bukti, penautan), dan nama "Procurement" dikembalikan ke makna supplier. **Kebutuhan §3.6 dari janji customer tetap dipertahankan utuh**, hanya labelnya yang berubah.
- **✚ Inventory sebagai grup tersendiri** — baru/terpisah per Blueprint (**B-5**): Warehouses · Stock Overview · Reservations · Movements · Transfers · Adjustments · Stocktake. Sebelumnya stok tersirat di dalam "Katalog"; kini berdiri sendiri mengikuti model `stock_items` agregat.
- **↻ Reports diperluas** — grup "Laporan" sudah ada, tetapi Blueprint §4 menambah sub-laporan: **Batch Profitability · Stock Aging · Customer Cohorts · Logistics** (yang lama: penjualan, margin, umur piutang, stok menipis, funnel checkout).
- **↻ Fulfillment** — grup "Pengiriman" diselaraskan ke cakupan Blueprint §4 Fulfillment (Picking · Packing · Shipping Labels · Shipments · Tracking · Delivery Exceptions); International Logistics kini terpisah (lihat ✚ di atas).

### 2.2 Peta penuh 14 grup Blueprint §4 → nav kami (tidak ada item yang boleh hilang)

Tabel ini adalah **checklist kelengkapan**, bukan pengulangan §2.1: kolom kedua menyalin submenu Blueprint §4 apa adanya (bahasa sumber, karena itulah yang dipakai Blueprint dan itulah yang diuji saat serah terima), kolom ketiga menunjuk baris nav tempat item itu hidup, kolom keempat modul kode pemilik tabelnya (Doc 09 §19 = satu-satunya pemilik pemetaan tabel→modul, Doc 11 §8.5). Kalau suatu saat sebuah submenu tidak punya tempat, ia **belum selesai dirancang**, bukan "tidak penting".

| Grup Blueprint §4 | Submenu verbatim Blueprint | Baris nav kami (§2) | Modul kode | Catatan pemetaan |
|---|---|---|---|---|
| Dashboard | Overview, Sales KPI, Order Monitoring, Payments, Alerts | Ringkasan hari ini | Orders + Payments + Reporting | `Order Monitoring` = daftar order hidup + transisi yang macet, bukan tabel baru; ia membaca `orders` + `order_events` |
| Catalog | Products, Variants/SKUs, Categories, Brands, Collections/Drops, Pricing, Media | Katalog | Catalog | `Media` secara fisik adalah tabel `media` **milik modul Content** (polimorfik, S-3) — layarnya tinggal di Katalog, tabelnya tidak |
| Sales | All Orders, Ready-to-Ship Orders, Pre-Orders, Request Orders, Returns, Cancellations | Penjualan (Orders) | Orders | keempat "jenis" adalah **filter `order_type`** (`ready_stock`/`preorder`/`request_order`), bukan empat tabel; Returns = `returns`+`return_items`; Cancellations = transisi + `reason`, bukan tabel |
| Pre-Order | Campaigns/Batches, Batch Products, Participants, Closing, Consolidation, Cost Allocation | Batch & Pre-order | PreOrder | `Closing` = aksi transisi `open → closing → closed` (idempoten, Blueprint §7); `Cost Allocation` di sini = alokasi biaya batch, yang landed cost lintas negara ada di Logistics |
| Request/Sourcing | Incoming Requests, Sourcing, Quotations, Approvals, Quote-to-Order | Request / Penawaran | Sourcing | modul kita bernama `Sourcing` (bukan `Quotes`, Doc 11 §8.5); `Approvals` = gate internal sebelum kuotasi dikirim |
| Procurement | Suppliers, Purchase Requisitions, Purchase Orders, Supplier Invoices, Goods Receipts | Procurement (grup baru, §2.1 ✚) | Procurement | `Purchase Requisitions` **tidak** jadi tabel sendiri: ia `purchase_orders.status = draft/pending_approval` (Doc 11 §4.3) |
| Inventory | Warehouses, Stock Overview, Reservations, Movements, Transfers, Adjustments, Stocktake | Inventory (grup baru, §2.1 ✚) | Inventory | `Adjustments` = `stock_movements` dengan alasan + approval, bukan kolom yang diedit langsung |
| International Logistics | Forwarders, Consolidations, Cargo, Customs, Landed Costs, Milestones | International Logistics (grup baru, §2.1 ✚) | Logistics | **label menu = Blueprint §4, nama modul = `Logistics`** (Blueprint §3); keduanya sengaja berbeda, jangan "disamakan" (Doc 11 §8.5) |
| Fulfillment | Picking, Packing, Shipping Labels, Shipments, Tracking, Delivery Exceptions | Pengiriman → Fulfillment | Fulfillment | modul kita `Fulfillment` (bukan `Shipping`, §8.5); `shipments` + `shipment_order_items` dimiliki Fulfillment meski `order_id` ditulis Orders |
| Billing & Finance | Invoices, DP/Installments, Additional Charges, Payments, Refunds, Receivables, Payables, Expenses, Cash/Bank | Penagihan (Billing) | Billing + Payments | `Refunds` milik **Payments** (Blueprint §3 menaruh refund di Billing & Payment); `Payables`/`Expenses`/`Cash/Bank` **tidak** jadi tabel di MVP — faktanya dilayani `supplier_invoices` + `payment_ledger` + `receivables` (Doc 09 §20); tetap dua baris `payables`/`expenses` yang belum ada skemanya, jadi layarnya menunggu keputusan klien, tidak boleh dihitung sudah selesai |
| Marketing & CMS | Banners, Content Pages, Vouchers, Campaigns, SEO, Reviews | Konten + Promosi + Ulasan & Media | Content + Promotion + UGC | `Content Pages` = `pages` + `content_blocks` ber-versi; `Banners` = blok bertipe banner + `media` (S-3), bukan tabel `banners` sendiri; `Reviews` masuk grup ini di Blueprint tapi moderasinya dilayani modul UGC |
| CRM & Support | Customers, Addresses, Tickets, Disputes, Notifications | Customer + Support | Identity + Support + Notification | `Disputes` adalah **jenis tiket**, bukan tabel `disputes` (Doc 09 §19); `Addresses` = `addresses` milik Identity |
| Reports | Sales, Gross Margin, Batch Profitability, Stock Aging, Finance, Logistics, Customer Cohorts | Laporan | Reporting (tanpa tabel) | semua tujuh adalah **view/read model** (§18 Doc 09); `Gross Margin` = view atas `order_charges`/`landed_cost_allocations`, tidak pernah kolom yang ditulis (S-5) |
| System | Staff, Roles/Permissions, Settings, Webhooks, Audit Logs, Jobs, Feature Flags | Pengaturan | Identity + Settings + Audit + Payments | `Staff` = `users` ber-`is_operator = true` (kolom `kind` lama dihapus, diganti dua boolean — Doc 11 §4.4 A; tabel `staff_users` tetap **tidak dibuat**, §8.5); `Roles/Permissions` = `roles`/`permissions` + pivot spatie `model_has_roles`/`model_has_permissions`/`role_has_permissions` + `role_scopes` (pivot lama `role_user`/`permission_role` **diganti**, Doc 11 §4.4 B); `Webhooks` = `webhook_receipts` (Payments) untuk jejak callback provider, sementara `inbox_events`/`outbox_events`/`idempotency_keys` adalah trio yang dimiliki `app/Platform/` (M-1); `Jobs` = dasbor Horizon tujuh antrian §8.1 |

Tiga hal yang **tidak** boleh dibaca dari tabel ini sebagai izin untuk menambah skema: nama submenu bukan tabel, jumlah baris bukan jumlah layar (§3 yang menentukan), dan "baris nav kami" boleh digabung asalkan setiap submenu Blueprint tetap punya satu tempat kerja.

## 3. Screen-by-screen: field, aksi, dan alasan

### 3.1 Batch & Pre-order (inti)
Buat batch: nama/kode, periode buka-tutup, ETA estimasi (tanggal + toleransi hari), status (`draft/open/closed/arrived/cancelled`), deposit rule (% atau flat, min/max), jatuh tempo pelunasan (default N hari **setelah barang tiba**), kebijakan deposit saat telat (hangus/voucher/perpanjangan manual), batas qty per akun, asal negara/toko sumber (dipakai badge PDP).
Aksi per batch: tutup paksa · geser ETA (memicu notif massal ke anggota batch) · tandai tiba/GRN per item (parsial) · batalkan karena supplier (refund massal + pilih metode) · export posisi bayar.
Data yang harus tampil: jumlah unit vs kuota, uang masuk (DP) vs piutang pelunasan, jumlah akun, item yang belum di-GRN, order `on_hold`.
Why dari sisi customer: PDP men-show periode PO + kuota, halaman tracking memisah lane PO, tagihan memisah DP/pelunasan/pajak-EMS.

### 3.2 Katalog & varian
Produk: nama, deskripsi (blocks), kategori + koleksi/fandom (2 sumbu), asal negara, media (gallery berurutan + alt text), status publish, mode pemenuhan (RTS/PO/drop).
Varian: matriks berdimensi (mis. karakter × ukuran) dengan harga, kuota, stok fisik, SKU, media per varian, dan status aktif — karena PDP memaksa "pilih varian dan ukuran terlebih dahulu" sebelum qty bisa diisi.
Stok: dua kolom terpisah (`physical_qty` RTS, `batch_quota` PO) + ledger pergerakan + alert minimum.
Bulk: import CSV/XLSX (nama, varian, kuota, harga, gambar per folder), dan "salin produk ke batch baru".

### 3.3 Request / Penawaran
Antrian: pengajuan masuk + status (baru, ditinjau, penawaran diterbitkan, diambil, kedaluwarsa, ditolak) + SLA timer + filter per akun.
Buat penawaran: per baris item harga unit, qty bisa dipenuhi, mode (RTS/PO + batch mana), masa berlaku, catatan internal, biaya kirim diingatkan dihitung saat checkout.
Aksi: terbitkan (→ notif ke akun itu saja) · perbaiki setelah dinegosiasi · tolak dengan alasan (tersimpan di riwayat customer) · klaim stok (reservasi) & lepas otomatis saat kedaluwarsa.
Kontrol penting: visibilitas privat ditegakkan di server (policy), admin bisa melihat semua, customer hanya miliknya.

### 3.4 Penagihan (Billing) — layar yang paling berubah akibat temuan
Model: **invoice = 1 tagihan ber-jatuh-tempo**, order = 1 kejadian. Satu order PO bisa memancarkan 3–4 invoice: DP harga barang, pelunasan harga barang, pajak+pos internasional, ongkir domestik (dan ongkir ulang bila retur).
Screen: daftar invoice (filter tipe/status/akun/batch) · detail (nominal, jatuh tempo, kanal bayar, riwayat percobaan, reminder terkirim) · aksi "tagih manual" (buat invoice tambahan dengan alasan) · "tandai lunas manual" (dengan bukti + double-approval) · "batal tagih" · "rekonsiliasi" (selisih settlement vs ledger) · laporan umur piutang (H+, 7, 14, 30).
Konsekuensi arsitektur: lihat ADR-002 (amandemen model uang di `01` §5).

### 3.5 Pengiriman
Kelola metode: kurir/layanan, tipe paket (tier ukuran), tarif (per zona/berat/tier), aktif/nonaktif, waktu estimasi; mode "panduan saja" (customer memilih, admin hitung manual) sebagai warga kelas satu.
Operasi: daftar paket siap pilih per batch, cetak label, **input resi massal (CSV/paste)**, koreksi resi, tandai exception (`returned_to_sender`, tertahan >N hari), trigger notif per tahap lane.
Lane tracking: editor tahap per jalur (RTS lokal, PO impor, request, marketplace_purchase) dengan sumber data tiap tahap (API kurir / manual) — karena halaman status customer menampilkan tahapan berbeda per jalur.

### 3.6 MarketplacePurchase (jalur beli-atas-nama; dahulu bernama "Procurement", B-4)
Siklus hidup kode (aturan RS-5 di Doc 02): `issued → used_by_buyer → reported → verified → linked_to_shipment`, dan `expired/revoked` sebagai cabang. Penegakan di server: **satu kode untuk satu paket**, tidak bisa dipakai ulang setelah terverifikasi, dan laporan buyer wajib disertai nomor order/paket yang cocok sebelum admin bisa memverifikasi.
Screen: daftar kode per batch/order · terbitkan kode (nominal, marketplace, batas pakai) · antrian "dilaporkan buyer" (bukti + nomor pesanan pihak ketiga) · verifikasi (role Finance/CS dengan alasan) · tautkan ke shipment lane `marketplace_purchase` (dahulu `procurement`) · unggah bukti bayar & resi awal · status "lacak dilakukan di platform asal" dengan tautan + tanggal lapor.
Regulatoris: bukti dan nominal disimpan untuk rekonsiliasi & sengketa, tidak pernah dipublikasikan ke akun lain. Kalau klien tidak memakai jalur ini, flag dimatikan — tapi layar dan aturannya tetap ada di desain.

### 3.7 Promosi & Drop
Voucher (per tipe, periode, kuota, min belanja, per kategori/koleksi/produk, per akun) · poin & tier + ledger poin · **Drop**: jadwal buka, item, batas 1 klaim per akun per item, antrian/lotre kalau diminta, dan pembatalan massal. (Aturan klaim satu-per-item terlihat dari sisi customer dan butuh enforcement di server + laporan klaim.)

### 3.8 Konten
Page editor per blok dengan **versi + tanggal berlaku** (kebijakan T&C berubah → kita harus tahu customer menyetujui versi mana saat checkout), template FAQ, banner carousel (slide, link, urutan, aktif), nav editor, SEO/OG per halaman, redirect. Semua teks ditulis klien kita sendiri.

### 3.9 Ulasan & moderasi media
Antrean ulasan (hanya dari akun dengan order selesai) → approve/reject dengan alasan, tampilkan nama/akun yang boleh disamarkan, moderasi lampiran (foto request/banner user), alt text wajib sebelum publish.

### 3.10 Customer & sesi
Cari akun (email/WhatsApp) · riwayat order/invoice/request/favorit dalam satu profil (360° view untuk CS) · blokir/perpanjang jatuh tempo · keluar semua perangkat atas nama user · preferensi notifikasi · ekspor & penghapusan data pribadi (hak user) dengan catatan legal-hold untuk transaksi.
Mekanisme sesi: daftar perangkat aktif dibaca dari `user_sessions` (Doc 09 §2); "keluar semua perangkat" menulis `revoked_at` pada semua baris sesi user itu, bukan menghapus akun. Penghapusan data pribadi adalah transisi status (`deletion_requested` → `deleted` setelah legal-hold transaksi selesai), bukan `DELETE` baris — karena itu tidak ada permission `user.delete` di matriks §1.3. Admin tidak pernah memegang password customer; reset password atas nama user mengirim alur reset ke email user, bukan menampilkan password baru. Hak layar ini dibundel di `customer-support` (`user.view`/`user.update`, `ticket.*`), grant tambahan lewat §1.4.

### 3.11 Pengaturan & feature flag
Brand (nama, logo, legal, kontak) · tema aktif · bahasa aktif (ID wajib, EN dari awal) · payment driver + credential + mode sandbox/live + **toggle metode per gateway** · shipping driver · WA/email driver · **toggle login sosial per provider** (persis pola "belum diaktifkan admin" di sisi customer) · batas qty/anti-tengkul · maintenance mode per area.
Toggle login sosial hanya berlaku di **storefront** (Google, X — Doc 11 §4.4 C). Tidak ada toggle sosial untuk admin: login admin selamanya email+password+2FA (§1.1), jadi flag ini tidak boleh muncul sebagai opsi di halaman login admin. Provider X memakai `socialiteproviders/twitter` (OAuth 2.0); driver `twitter` bawaan Socialite memakai OAuth 1.0a yang sudah ditinggalkan X dan tidak dipasang (ADR-012 (RBAC/identitas/dependensi) D-4).

## 4. Matriks "janji customer → layar admin"

| Janji ke customer | Tanpa layar ini, janji tidak bisa ditepati |
|---|---|
| "PO, estimasi tiba X minggu" | batch ETA + notif saat ETA bergeser |
| "Deposit lalu lunas" | invoice engine + reminder jatuh tempo |
| "Tagihan pajak & pos luar negeri terpisah" | tagih manual beralasan + tipe invoice |
| "Cek status 3 jalur" | lane tracking editor + event per tahap |
| "Resi muncul setelah dikirim" | input resi massal + API kurir |
| "Penawaran request dijawab ≤2 hari" | antrian SLA + reminder escalation |
| "Ulasan hanya pembeli terverifikasi" | moderasi + validasi order selesai |
| "Satu klaim per item di drop" | aturan klaim + laporan |
| "Pilihan ongkir per ukuran paket" | tarif tier paket + mode panduan |
| "Barang tidak sesuai bisa retur" | workflow retur + refund partial |
| "Dijawab CS cepat" | tiket + 360° profil |

## 5. Prioritas pembangunan admin (urutan relatif — jadwal belum diputus)

Urutan di bawah adalah **prioritas relatif dan dependensi fungsional antar-layar** (layar mana butuh layar lain lebih dulu), **bukan** pemetaan tetap ke sprint. Pemetaan akhir ke sprint/Phase **belum diputus klien**: cakupan penuh Blueprint menuntut **±22–26 minggu (11–13 sprint)** dengan baseline **J-a**, dan angka ±18 minggu hanya bertahan pada **opsi J-b/J-c** yang memotong cakupan — semuanya keputusan terbuka **Q-1** (Doc 03 §1 dan Doc 11 §5/§6). Dokumen ini sengaja tidak menjual tanggal; begitu Q-1 dijawab, tabel tahap di bawah dipetakan ke rencana di Doc 03.

| Tahap (relatif) | Layar admin |
|---|---|
| 0 | kerangka + guard `admin`/`web` (cookie sesi Sanctum) + 2FA + RBAC spatie granular + audit + flag |
| 1 | katalog (produk/varian/media/kuota) + konten dasar |
| 2 | order masuk (baca) + penagihan dasar (intent) + flag payment |
| 3 | penjualan penuh + pengiriman (metode/tarif/resi) + tracking lane + notifikasi + retur + tiket |
| 4 | batch & PO penuh + billing multi-invoice + laporan posisi bayar |
| 5 | request/penawaran + SLA + visibilitas |
| 6 | promosi/drop + konten (versi T&C) + moderasi ulasan |
| 7 | laporan, rekonsiliasi lanjutan, ekspor, UAT |

Catatan status layar: nama status yang tampil di layar admin mengikuti enum kanonik **Doc 11 §8.3** (`order_status` · `payment_status` · `procurement_status` · `fulfillment_status` + lane `fulfillment_lane`); `awaiting_batch` dan `ready_to_ship` adalah **tahap fulfillment**, bukan status order, dan nilai lama seperti `pending_payment` tidak dipakai lagi (→ `awaiting_payment`).

## 6. Pertanyaan yang perlu dijawab klien sebelum spec admin final

Apakah jalur beli-atas-nama (marketplace pihak ketiga) dipakai di toko klien kita? Siapa yang boleh menyetujui "tandai lunas manual"? Apakah drop perlu lotre/antrian atau cukup first-come? Berapa ambang refund yang butuh persetujuan ganda? Apakah pajak/pos internasional ditagih otomatis setelah GRN atau manual per batch? Bahasa mana yang wajib siap saat launch? Siapa saja yang boleh memegang `is_admin` (bypass berjejak, §1.6), dan bagaimana prosedur review berkala daftarnya? Siapa nama pemilik permission CMS dan moderasi UGC (Q-8, Doc 11 §6) — mekanismenya sudah tersedia lewat grant langsung (§1.4), tetapi orangnya tetap keputusan klien.
