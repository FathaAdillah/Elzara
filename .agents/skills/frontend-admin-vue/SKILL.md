---
name: frontend-admin-vue
description: Implementasikan alur, route, layout, dan integrasi Laravel untuk aplikasi admin Vue di Snowego. Gunakan saat mengubah apps/admin, sesi operator, navigasi, atau konsumsi kontrak OpenAPI.
---

# Frontend admin Snowego

Repo Snowego berada di `elzara`. Admin saat ini memakai Nuxt 4 SSR, Vue 3, TypeScript, Nuxt UI v4, dan Tailwind CSS 4. Periksa manifest dan lockfile untuk versi terpasang. Gunakan arsitektur yang berjalan saat ini sebagai rujukan implementasi ketika dokumen lama menyebut Next.js.

## Titik masuk

| Area | Berkas |
|---|---|
| Root, locale dan provider UI | [app.vue](../../../apps/admin/app/app.vue) |
| Dashboard pada `/` | [index.vue](../../../apps/admin/app/pages/index.vue) |
| Form login dan challenge 2FA | [login.vue](../../../apps/admin/app/pages/login.vue) |
| Shell admin dan menu operator | [layouts/admin.vue](../../../apps/admin/app/layouts/admin.vue) |
| Guard SSR dan navigasi klien | [admin-session.ts](../../../apps/admin/app/middleware/admin-session.ts) |
| Pemeriksaan sesi terhadap Laravel | [admin-session.get.ts](../../../apps/admin/server/routes/internal/admin-session.get.ts) |
| Kontrak dan tipe API | [OpenAPI](../../../packages/api-contracts/openapi.yaml) |

Halaman admin memakai routing berbasis berkas dan layout `admin`. URL lama `/overview` mengarah ke `/`. Pratinjau data contoh dinonaktifkan secara default. Di development Docker, gunakan origin admin yang tercatat pada konfigurasi Compose. [Panduan Docker](../../../docker/README.md)

## API dan sesi

- Tulis data bisnis lewat Laravel. Gunakan tipe `paths` atau `components` dari `@elzara/api-client` jika kontrak sudah tersedia. Regenerasi klien melalui `scripts/codegen.sh` ketika kontrak berubah. [Kontrak API](../../../docs/12-api-contract.md)
- Mutasi sesi admin memakai cookie Laravel dan header `X-XSRF-TOKEN`. Pakai [admin-auth.ts](../../../apps/admin/app/utils/admin-auth.ts) untuk login, verifikasi, dan logout. UI membuka dashboard setelah endpoint pemeriksaan sesi mengonfirmasi sesi operator. Pengecualian 2FA lokal hanya dikendalikan Laravel melalui konfigurasi development untuk satu email.
- Gunakan data sesi yang benar-benar dikirim API. Untuk modul ber-RBAC, tampilkan menu dan aksi berdasarkan kemampuan server yang sudah tersedia, dan pertahankan pemeriksaan di backend. [Kebutuhan admin](../../../docs/06-admin-dashboard-requirements.md)
- Letakkan fetch, state, dan penanganan error sesuai pola fitur yang sudah ada. Ekstrak composable ketika beberapa layar berbagi alur. Tampilkan keadaan memuat, kosong, gagal, dan aksi ulang jika relevan.

## Pola UI dan verifikasi

Gunakan [nuxt-ui](../nuxt-ui/SKILL.md) untuk komponen dan [admin-ui-slicing](../admin-ui-slicing/SKILL.md) untuk penyusunan layar. Gunakan [elzara-admin-ui](../elzara-admin-ui/SKILL.md) untuk detail login, 2FA, dashboard, dan palet lavender.

Untuk grafik tren admin, pertahankan Vue ApexCharts melalui [komponen sisi klien](../../../apps/admin/app/components/OperationsTrendChart.client.vue). Pertahankan ukuran ruang grafik saat memuat, pisahkan data contoh dari data API di permukaan pengguna, dan gunakan format tanggal serta angka Indonesia. Jalankan `npm run typecheck` dan `npm run build` dari `apps/admin` jika mengubah kode. Uji hanya perjalanan pengguna yang terdampak, termasuk akses bertamu dan akses bersesi jika route berubah.
