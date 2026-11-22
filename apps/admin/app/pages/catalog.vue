<script setup lang="ts">
import type { CatalogCsvImportResult, CatalogSearchResult } from '~/utils/admin-api'

definePageMeta({ layout: 'admin', middleware: 'admin-session' })
useSeoMeta({ title: 'Katalog', robots: 'noindex, nofollow' })
const { can } = useAdminAccess()

const statuses = [{ label: 'Semua status', value: 'all' }, { label: 'Draft', value: 'draft' }, { label: 'Aktif', value: 'active' }, { label: 'Diarsipkan', value: 'archived' }]
const query = ref('')
const status = ref('all')
const filters = ref<Record<string, string[]>>({})
const pages = ref<CatalogSearchResult[]>([])
const searching = ref(false)
const searchError = ref('')
const facetLabels: Record<string, string> = { brand: 'Brand', category: 'Kategori', collections: 'Koleksi', origin_country: 'Negara asal', fulfillment_modes: 'Mode', status: 'Status' }
const hits = computed(() => pages.value.flatMap(page => page.data))
const last = computed(() => pages.value.at(-1))

function searchPath(cursor?: string | null) {
  const params = new URLSearchParams()
  if (query.value.trim()) params.set('q', query.value.trim())
  if (status.value !== 'all') params.set('status', status.value)
  for (const [field, values] of Object.entries(filters.value)) {
    if (values.length) params.set(field, values.join(','))
  }
  if (cursor) params.set('cursor', cursor)
  const encoded = params.toString()
  return `/catalog/search${encoded ? `?${encoded}` : ''}`
}

async function search(more = false) {
  if (!can('catalog.view') || searching.value) return
  searching.value = true
  searchError.value = ''
  try {
    const page = await adminRead<CatalogSearchResult>(searchPath(more ? last.value?.meta.next_cursor : null))
    pages.value = more ? [...pages.value, page] : [page]
  } catch (cause) {
    searchError.value = catalogError(cause)
  } finally {
    searching.value = false
  }
}

function toggleFacet(field: string, value: string) {
  const current = filters.value[field] ?? []
  filters.value = { ...filters.value, [field]: current.includes(value) ? current.filter(item => item !== value) : [...current, value] }
  search()
}

const file = ref<File | null>(null)
const dryRun = ref(true)
const reason = ref('')
const importing = ref(false)
const importResult = ref<CatalogCsvImportResult | null>(null)
const importErrors = ref<{ pointer?: string, code?: string, detail?: string }[]>([])
const importError = ref('')
const canImport = computed(() => can('catalog.create') && can('catalog.update'))

function pickFile(event: Event) {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function importCsv() {
  if (!canImport.value || !file.value || importing.value || (!dryRun.value && !validAdminReason(reason.value, 5))) return
  importing.value = true
  importResult.value = null
  importErrors.value = []
  importError.value = ''
  const body = new FormData()
  body.set('file', file.value)
  body.set('dry_run', dryRun.value ? 'true' : 'false')
  if (reason.value.trim()) body.set('reason', reason.value.trim())
  try {
    importResult.value = (await adminUpload<{ data: CatalogCsvImportResult }>('/catalog/products/import', body)).data
    if (!dryRun.value) reason.value = ''
  } catch (cause) {
    const data = (cause as { data?: { errors?: typeof importErrors.value } }).data
    importErrors.value = data?.errors ?? []
    importError.value = catalogError(cause)
  } finally {
    importing.value = false
  }
}

const reindexing = ref(false)
const reindexNotice = ref('')
async function reindex() {
  if (!can('catalog.update') || reindexing.value) return
  reindexing.value = true
  reindexNotice.value = ''
  try {
    await adminMutate('/catalog/search/reindex', 'POST', {})
    reindexNotice.value = 'Reindex diantrekan di antrean imports.'
  } catch (cause) {
    reindexNotice.value = catalogError(cause)
  } finally {
    reindexing.value = false
  }
}

function catalogError(cause: unknown) {
  const status = (cause as { statusCode?: number } | null)?.statusCode
  if (status === 422) return 'Data ditolak. Periksa input atau baris CSV yang ditandai.'
  if (status === 503) return 'Mesin pencari tidak tersedia. Coba lagi atau jalankan reindex.'
  return adminApiError(cause)
}

onMounted(() => search())
</script>

<template>
  <div class="space-y-6 p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold">Katalog</h1>
      <div v-if="can('catalog.view')" class="flex flex-wrap gap-2">
        <UButton variant="outline" icon="i-lucide-download" href="/api/v1/admin/catalog/products/export" external>Ekspor CSV</UButton>
        <UButton v-if="can('catalog.update')" variant="outline" icon="i-lucide-refresh-cw" :loading="reindexing" @click="reindex()">Reindex pencarian</UButton>
      </div>
    </div>
    <AdminNoAccess v-if="!can('catalog.view')" />
    <template v-else>
      <UAlert v-if="reindexNotice" :title="reindexNotice" />
      <UCard>
        <form class="flex flex-wrap items-end gap-3" role="search" @submit.prevent="search()">
          <UFormField label="Cari produk, SKU, brand" class="min-w-64 flex-1">
            <UInput v-model="query" class="w-full" icon="i-lucide-search" :maxlength="200" placeholder="mis. kaos neko, tshirt, SKU" />
          </UFormField>
          <UFormField label="Status">
            <USelect v-model="status" :items="statuses" class="w-44" @update:model-value="search()" />
          </UFormField>
          <UButton type="submit" :loading="searching">Cari</UButton>
        </form>
      </UCard>
      <UAlert v-if="searchError" color="error" :title="searchError" />
      <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
        <aside v-if="last" class="space-y-4" aria-label="Filter facet">
          <div v-for="(buckets, field) in last.facets" :key="field">
            <template v-if="buckets.length">
              <h2 class="mb-2 text-sm font-semibold">{{ facetLabels[field] ?? field }}</h2>
              <div class="flex flex-wrap gap-2">
                <UButton v-for="bucket in buckets" :key="bucket.value" size="xs" :variant="filters[field]?.includes(bucket.value) ? 'solid' : 'outline'" :aria-pressed="filters[field]?.includes(bucket.value) ?? false" @click="toggleFacet(String(field), bucket.value)">
                  {{ bucket.value }} ({{ bucket.count }})
                </UButton>
              </div>
            </template>
          </div>
        </aside>
        <section class="space-y-3" aria-live="polite">
          <p v-if="last" class="text-sm text-muted">{{ last.meta.total }} produk ditemukan.</p>
          <p v-if="last && !hits.length" class="text-sm text-muted">Tidak ada produk yang cocok.</p>
          <UCard v-for="hit in hits" :key="hit.id">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h3 class="font-semibold">{{ hit.name }}</h3>
                <p class="text-sm text-muted">{{ hit.brand_name ?? 'Tanpa brand' }} · {{ hit.category }} · {{ hit.skus?.join(', ') }}</p>
              </div>
              <div class="flex items-center gap-2">
                <UBadge :color="hit.status === 'active' ? 'success' : 'neutral'">{{ hit.status }}</UBadge>
                <span v-if="hit.price_min !== null && hit.price_min !== undefined" class="text-sm">{{ hit.currency_code }} {{ hit.price_min.toLocaleString('id-ID') }}</span>
              </div>
            </div>
          </UCard>
          <UButton v-if="last?.meta.has_more" variant="outline" :loading="searching" @click="search(true)">Muat berikutnya</UButton>
        </section>
      </div>
      <UCard v-if="canImport">
        <template #header><h2 class="font-semibold">Import CSV</h2></template>
        <form class="space-y-4" @submit.prevent="importCsv()">
          <UFormField label="Berkas CSV" required help="Maks 5 MB / 5000 baris; header sama dengan hasil ekspor. Satu baris salah membatalkan seluruh berkas.">
            <input type="file" accept=".csv,text/csv" class="text-sm" @change="pickFile">
          </UFormField>
          <UCheckbox v-model="dryRun" label="Dry run (validasi saja, tanpa menulis)" />
          <UFormField label="Alasan perubahan" :required="!dryRun" help="Wajib saat bukan dry run; 5–320 karakter.">
            <UTextarea v-model="reason" class="w-full" :maxlength="320" :disabled="importing" />
          </UFormField>
          <UButton type="submit" :loading="importing" :disabled="!file || (!dryRun && !validAdminReason(reason, 5))">{{ dryRun ? 'Validasi CSV' : 'Import CSV' }}</UButton>
        </form>
        <UAlert v-if="importResult" class="mt-4" color="success" :title="`${importResult.dry_run ? 'Dry run lolos' : 'Import selesai'}: ${importResult.rows} baris, produk +${importResult.products_created}/~${importResult.products_updated}, varian +${importResult.variants_created}/~${importResult.variants_updated}`" />
        <template v-if="importError">
          <UAlert class="mt-4" color="error" :title="importError" />
          <ul v-if="importErrors.length" class="mt-2 max-h-64 overflow-auto text-sm">
            <li v-for="(item, index) in importErrors" :key="index"><code>{{ item.pointer }}</code> {{ item.code }} — {{ item.detail }}</li>
          </ul>
        </template>
      </UCard>
    </template>
  </div>
</template>
