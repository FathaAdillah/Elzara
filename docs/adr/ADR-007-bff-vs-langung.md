# ADR-007 — BFF Next.js versus akses API langsung dari browser (model sesi dan otorisasi)

Tanggal: 2026-11-17 · Status: **Accepted untuk mekanisme (amandemen pemilik 2026-11-18, lihat bawah) — persetujuan kontrol sesi dikonfirmasi pada 2026-11-20; lokasi artefak tertulis dan jawaban Q-2 IdP/SSO masih terbuka** · Menyelesaikan bentrok **B-1/B-2** sisi akses (Doc 11 §3: Blueprint §18 butir 6, "baru → ADR-007; wajib sebelum Sprint 1") · Rujukan induk: Blueprint §7 konvensi, §12, §13 (ADR-003) · Register mengikat: Doc 11 §4.4 C/I; alasan pemilihan paket ada di ADR-012

## Konteks

Blueprint §7 sengaja meninggalkan kalimat terbuka: "OAuth2/OIDC **atau** server-side session/BFF sesuai threat model". Setelah B-1 memaksa dua aplikasi Next.js terpisah dari API Laravel, pertanyaan itu tidak lagi bisa dijawab "nanti" karena ia menentukan **tiga hal sekaligus**: bentuk cookie/token di browser, apakah `/api/v1` harus terekspos publik dengan CORS, dan — yang paling Operasional — **di mana klien TypeScript hasil generate (B-2) dipanggil**. Blueprint §12 sendiri sudah condong ke satu arah ("Rekomendasi: Browser → Next.js BFF untuk sensitive flows → Laravel API") tapi menautkannya pada threat model yang belum ada di repo (B-14: `docs/threat-model.md` adalah dokumen baru).

Ini **mengunci Sprint 1**: scaffold auth, middleware, konfigurasi CORS, CSP, dan pola pemanggilan API di kedua app semuanya bergantung pada jawaban ini, dan menggantinya setelah katalog + cart jadi berarti menulis ulang lapis data di dua frontend. Karena itu status ADR ini pada 2026-11-17 adalah Proposed: bentuknya siap, tanda-tangannya belum. **Pemilik proyek memutuskannya pada 2026-11-18 — lihat amandemen di bawah; teks usulan awal dipertahankan sebagai jejak alasan, bukan sebagai aturan yang berlaku.**

## Dua bentuk yang dipertimbangkan

**Bentuk A — BFF di Next.js (arah rekomendasi Blueprint §12).**
Browser bicara hanya ke origin aplikasinya sendiri (`www.` / `admin.`). Route handler Next.js memegang sesi, menyisipkan kredensial server-to-server ke `api`, dan memproyeksikan DTO ke client component. Konsekuensi positif: token/rahasia tidak pernah ada di browser (Blueprint §12: "menghindari penyimpanan bearer token di browser"), otorisasi ditegakkan di dua tempat (Laravel policy sebagai pemegang kebenaran + penyaring di BFF), cookie bisa `Secure; HttpOnly; SameSite=Lax`, permukaan publik `/api/v1` bisa ditutup untuk rute sensitif, dan cache CDN tidak pernah memegang data pelanggan. Konsekuensi negatif: satu lapis tambahan yang harus diuji dan di-deploy, latency ekstra untuk rute yang cuma meneruskan, risiko "BFF jadi tempat logika bisnis" (dilarang — aturan B2: mutasi hanya lewat Command di API), CSRF kembali menjadi kewajiban nyata, dan server-side rendering memanggil API internal membuat keputusan ADR-009 tentang bypass admission lebih sensitif.

**Bentuk B — browser memanggil API langsung.**
Akses token berumur pendek (OAuth2/OIDC atau token Laravel Sanctum-style) disimpan di memori aplikasi, refresh diam-diam; `/api/v1` publik dengan CORS ketat per origin; otorisasi tetap di server (policy), tapi setiap rute harus menganggap dirinya dijangkau langsung dari internet. Konsekuensi positif: satu lapis lebih sedikit, skalabilitas frontend/API terpisah secara jujur, cocok untuk aplikasi mobile di masa depan (saat ini di luar cakupan Doc 02 §8), dan tanpa masalah CSRF model cookie. Konsekuensi negatif: permukaan serangan lebih besar ( enumerasi rute, credential stuffing langsung ke API, butuh rate limit + bot defense di edge untuk **setiap** rute bukan hanya halaman), token di JavaScript berarti XSS menjadi theft-of-session, CORS salah satu baris berarti kebocoran data lintas origin, dan seluruh kontrol admission ADR-009 wajib menjangkau rute API — bukan cuma halaman.

## Keputusan (usulan awal 2026-11-17 — diganti amandemen di bawah)

**D-1 — Arah yang kami usulkan:** Bentuk A (BFF) untuk **seluruh** alur sensitif di kedua app: login, cart, checkout, order, tagihan, refund, seluruh admin. Catalog **GET publik** boleh memanggil API/CDN langsung kalau kebijakan cache cocok (Blueprint §12 mengizinkan persis pengecualian ini). Usulan ini mengikuti rekomendasi Blueprint §12 dan menurunkan cakupan threat model kita.

**D-2 — Aturan yang berlaku untuk kedua bentuk, tidak tergantung pilihan:** kebenaran otorisasi selalu di Laravel (policy/gate), tidak pernah di BFF atau di komponen UI (Doc 01 §8: proteksi rute admin server-side; Doc 11 B-10 untuk 10 role). BFF adalah proksi + proyeksi, bukan tempat keputusan bisnis.

**D-3 — Kontrak tidak boleh bercabang:** klien TypeScript hasil generate dari `packages/api-contracts` dipakai di kedua bentuk; yang berubah hanyalah **tempat** ia dipanggil (route handler/server component untuk A, komponen klien untuk B). Ini alasan ADR-007 dianggap *menentukan cara pakai klien generate*, bukan detail implementasi.

**D-4 — Batas yang tidak boleh dilewati apa pun hasilnya:** tidak ada rahasia di `NEXT_PUBLIC_*` (Blueprint §12), tidak ada kartu/PII di log, dan sesi admin dipisah dari sesi storefront dengan kebijakan cookie dan (bila bentuk B) audience token terpisah.

## Amandemen 2026-11-18 — mekanisme diputuskan pemilik: cookie sesi same-site (Sanctum)

Pemilik proyek memilih **`laravel/sanctum` dengan `statefulApi` (cookie sesi), bukan bearer token dan bukan BFF wajib**. Yang membuat pilihan ini aman adalah topologi edge kita yang sudah terkunci: **satu host dengan pemisah path** (`/api/v1/webhooks/`, `/api/`, `/storage/`, `/admin`, `/` — `docker/nginx/dev.conf`, ADR-001/Doc 01 §9). Karena halaman dan API berada pada origin yang sama, cookie sesi adalah **same-site**: `SameSite=Lax` cukup, tidak ada CORS lintas origin, dan tidak ada `SameSite=None`. Ini bukan alternatif A yang ditolak di tabel bawah — alternatif itu mengandaikan dua origin (`api.example.com` vs `www.example.com`); kita tidak memisahkan host.

**D-1a — Bentuk yang berlaku (Bentuk C):** browser memanggil `/api/v1/...` pada origin yang sama dengan cookie sesi; route handler/server component Next.js boleh meneruskan cookie yang sama untuk SSR, tetapi **tidak memegang sesi sendiri dan tidak menjadi tempat keputusan otorisasi**. Tidak ada lapis BFF wajib untuk alur sensitif, jadi pekerjaan Sprint 1 berubah dari "bangun BFF" menjadi "bangun guard + policy + CSRF". Pengecualian catalog GET publik lewat CDN tetap berlaku (Blueprint §12).

**D-2a — Kebijakan cookie konkret:** dua nama cookie berbeda (`elzara_web_session`, `elzara_admin_session`), keduanya `Secure; HttpOnly; SameSite=Lax; Path=/` — `Path=/` wajib karena cookie harus ikut terkirim ke `/api/`, jadi pemisahan admin/storefront dilakukan lewat **nama cookie + guard**, bukan lewat path. Cookie CSRF (`XSRF-TOKEN`) sengaja **tidak** HttpOnly karena dibaca JavaScript untuk header `X-XSRF-TOKEN`; itu bagian dari desain Sanctum, bukan kelalaian. Endpoint `sanctum/csrf-cookie` dipanggil sebelum mutasi pertama. Semua mutasi menuntut CSRF valid; tidak ada pengecualian untuk admin. **Mekanismenya tidak native di Laravel** (satu nama cookie per konfigurasi sesi): middleware permukaan yang berjalan sebelum `StartSession` menyetel nama cookie berdasarkan path — `/admin` dan `/api/v1/admin/*` memakai `elzara_admin_session`, sisanya `elzara_web_session`. Daftar kekurangan kerangka yang harus ditutup ada di Doc 11 §4.4 K.

**D-3a — Tidak ada token jangka panjang di browser:** `personal_access_tokens` **tidak dibuat**. Kredensial server-to-server (webhook gateway, worker internal) memakai tanda tangan/HMAC atau jaringan internal, bukan cookie sesi. `s2s` di spec tetap kategori terpisah.

**D-4a — Pemisahan sesi admin dan storefront jadi konkret:** guard `web` dan guard `admin` terpisah. Guard `admin` hanya menerima `users.is_operator = true` + `status = active` + 2FA terkonfirmasi (`staff_2fa_secrets`), dan hanya email+password (ADR-012 D-1/D-4). `Gate::before` untuk bypass `is_admin` **hanya dipasang pada guard admin** dan setiap pemakaiannya menulis `audit_logs` (`action = rbac.bypass`). Cookie storefront tidak pernah diterima guard admin, sehingga XSS di storefront tidak menjadi akses admin. Otorisasi web = kepemilikan + status akun; spatie tidak dipakai di storefront.

**D-5a — Konsekuensi pada kontrak dan tepi:** larangan "belum boleh ada `securitySchemes`" dicabut (Doc 11 §4.4 I) — `openapi.yaml` wajib memuat skema cookie + CSRF dan `security` per operasi, `x-elzara-auth` tetap sebagai penanda kategori, dan operasi admin menambah `x-elzara-permission`. Karena same-origin, konfigurasi CORS lintas origin **tidak dibuat**; yang tetap wajib adalah CSP per app, rate limit di edge **dan** `RateLimiter` di API untuk login/OTP/reset, serta `X-Actor-Type: web | admin | system` yang diisi server dari guard.

**D-6a — Persetujuan dan perubahan topologi:** (1) persetujuan pemilik proyek dan klien atas cookie sesi, CSRF, 2FA, dan pemisahan guard dikonfirmasi dalam percakapan proyek pada 2026-11-20. Lokasi artefak bertanda tangan masih menunggu kiriman, lihat `docs/threat-model.md` §6; (2) **Q-2 menyempit** menjadi "apakah klien punya IdP/SSO sendiri"; kalau ya, provider OIDC ditambahkan pada jalur login yang sama, bukan mengganti mekanisme sesi; (3) kalau produksi kelak memindahkan admin ke subdomain terpisah (`admin.example.com`), **keputusan ini harus dibuka kembali** untuk menilai origin API, cakupan cookie, dan aturan CSRF pada topologi produksi.

## Alternatif yang ditolak

| | Ditolak | Alasan kalah |
|---|---|---|
| A | Cookie sesi di-`Set-Cookie` dari `api.example.com` ke browser (tanpa BFF, tanpa token) | Cookie lintas-site menuntut `SameSite=None` + CSRF surface penuh; memecah halaman dan API jadi dua origin tidak memberi keuntungan apa pun atas dua bentuk di atas |
| B | Bentuk B penuh untuk **admin** juga | Admin adalah permukaan paling berbahaya (harga, override, refund, mark-lunas-manual); kehilangan HttpOnly + IP restriction/Zero Trust yang justru diizinkan Blueprint §12 untuk admin adalah kemunduran, bukan penyederhanaan |
| C | Bentuk A penuh, tanpa pengecualian catalog langsung | Memaksa seluruh trafik baca katalog lewat BFF: buang caching/ISR, menaikkan biaya compute, dan membuat ADR-009 (admission) jadi satu-satunya katup pengaman untuk halaman yang seharusnya cukup dilayan CDN |
| D | Dua model sekaligus (storefront BFF + API publik penuh tanpa pembatasan) | Dua jalur auth = dua jalur bug; kita kehilangan kemampuan membuktikan "tidak ada rute sensitif yang terjangkau publik" di threat model |

## Konsekuensi

**Bukti persetujuan:** `docs/threat-model.md` §6 mencatat konfirmasi pemilik proyek dan klien serta lokasi artefak yang masih ditunggu. Q-2 tetap menunggu jawaban apakah klien punya SSO/IdP sendiri. Jika ya, provider OIDC dapat ditambahkan sesuai D-6a.

**Konsekuensi Bentuk C yang berlaku sekarang:** tidak ada proses BFF tambahan yang harus di-deploy, tetapi **dua guard + dua nama cookie + CSRF** menjadi pekerjaan nyata Sprint 1 di `api`, dan kedua app Next.js wajib memanggil `sanctum/csrf-cookie` sebelum mutasi. CSP dirumuskan per app; rate limit login/OTP/reset wajib di edge dan di API. Kalau kelak admin dipindah ke subdomain terpisah, keputusan ini dibuka kembali (D-6a) — itu perubahan topologi, bukan setelan.

**Yang jadi mustahil di semua bentuk:** otorisasi "hanya menyembunyikan menu" (Ditolak oleh Blueprint §4 dan Doc 01 §8), dan token jangka panjang di localStorage.

**Kalau diabaikan dan coding jalan duluan:** keputusan ini menjadi refactor lintas dua frontend, dan itu persis alasan Doc 11 §3 menulisnya "wajib sebelum Sprint 1".
