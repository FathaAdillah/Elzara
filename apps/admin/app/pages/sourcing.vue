<script setup lang="ts">
import type { operations } from '@elzara/api-client'
import { adminRead } from '~/utils/admin-api'

definePageMeta({ layout: 'admin', middleware: 'admin-session' })
useSeoMeta({ title: 'Antrean pengajuan barang', robots: 'noindex, nofollow' })

type Queue = operations['listAdminProductRequests']['responses'][200]['content']['application/json']
type Status = Queue['data'][number]['status']
const labels: Record<Status, string> = {
  new: 'Baru', in_review: 'Ditinjau', quoted: 'Ditawarkan', accepted: 'Diterima',
  declined: 'Ditolak', expired: 'Kedaluwarsa', withdrawn: 'Ditarik',
}
const statuses = [{ value: 'all', label: 'Semua status' }, ...Object.entries(labels).map(([value, label]) => ({ value, label }))]
const modes = { ready_stock: 'Siap kirim', preorder: 'Pre-order' }
const date = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' })
const idr = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })
const formatDate = (iso: string) => `${date.format(new Date(iso))} WIB`
const { can } = useAdminAccess()
const status = ref('all')
const account = ref('')
const queue = ref<Queue>()
const failed = ref('')
const loading = ref(false)
let applied = ''

async function load(cursor?: string) {
  if (!can('sourcing.view') || loading.value) return
  const query = new URLSearchParams(cursor ? applied : '')
  if (cursor) {
    query.set('cursor', cursor)
  } else {
    if (status.value !== 'all') query.set('status', status.value)
    if (account.value.trim()) query.set('account', account.value.trim())
  }

  loading.value = true
  failed.value = ''
  try {
    const page = await adminRead<Queue>(`/product-requests?${query}`)
    if (!cursor) applied = query.toString()
    queue.value = cursor && queue.value ? { ...page, data: [...queue.value.data, ...page.data] } : page
  } catch {
    failed.value = 'Antrean tidak dapat dimuat. Silakan coba lagi.'
  } finally {
    loading.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <section class="space-y-5 p-6">
    <header>
      <h1 class="text-2xl font-semibold">Antrean pengajuan barang</h1>
      <p class="mt-1 text-sm text-muted">Pengajuan privat yang menunggu tindak lanjut tim sourcing.</p>
    </header>
    <AdminNoAccess v-if="!can('sourcing.view')" />
    <template v-else>
      <UCard>
        <form class="flex flex-wrap items-end gap-3" aria-label="Filter pengajuan" @submit.prevent="load()">
          <UFormField label="Status" name="status">
            <USelect v-model="status" :items="statuses" class="w-44" />
          </UFormField>
          <UFormField label="Email pelanggan" name="account" class="min-w-0 flex-1">
            <UInput v-model="account" type="email" maxlength="254" class="w-full" />
          </UFormField>
          <UButton type="submit" :loading="loading">Terapkan filter</UButton>
        </form>
      </UCard>
      <UAlert v-if="failed" color="error" :title="failed" role="alert" />
      <p v-if="loading && !queue" role="status" class="text-sm text-muted">Memuat pengajuan…</p>
      <p v-if="queue?.data.length === 0" class="text-sm text-muted">Tidak ada pengajuan yang cocok.</p>
      <ul v-if="queue?.data.length" class="space-y-3" aria-label="Daftar pengajuan">
        <li v-for="item in queue.data" :key="item.id">
          <UCard>
            <article :aria-label="item.title" class="space-y-3">
              <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                  <h2 class="break-words font-semibold">{{ item.title }}</h2>
                  <p class="break-all text-xs text-muted">{{ item.id }}</p>
                </div>
                <UBadge :color="item.status === 'new' ? 'warning' : 'neutral'" variant="subtle">{{ labels[item.status] }}</UBadge>
              </div>
              <p class="break-words text-sm">{{ item.customer_name }} · {{ item.customer_email }}</p>
              <p class="text-sm text-muted">Diajukan {{ formatDate(item.created_at) }}</p>
              <p v-if="item.sla_due_at" class="text-sm">Target tanggapan: {{ formatDate(item.sla_due_at) }}</p>
              <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-muted">Jumlah</dt><dd>{{ item.quantity }}</dd></div>
                <div><dt class="text-muted">Target harga</dt><dd>{{ item.target_price ? idr.format(item.target_price.amount_minor) : '—' }}</dd></div>
                <div><dt class="text-muted">Negara asal</dt><dd>{{ item.origin_country ?? '—' }}</dd></div>
                <div><dt class="text-muted">Preferensi</dt><dd>{{ item.preferred_mode ? modes[item.preferred_mode] : 'Fleksibel' }}</dd></div>
              </dl>
              <p v-if="item.brand || item.series || item.character_name" class="break-words text-sm">{{ [item.brand, item.series, item.character_name].filter(Boolean).join(' · ') }}</p>
              <a v-if="item.target_url" :href="item.target_url" rel="noopener noreferrer" target="_blank" class="inline-block break-all text-sm underline">
                {{ item.target_url }}
              </a>
              <p v-if="item.notes" class="whitespace-pre-wrap break-words text-sm">{{ item.notes }}</p>
            </article>
          </UCard>
        </li>
      </ul>
      <UButton v-if="queue?.meta.next_cursor" variant="outline" :loading="loading" @click="load(queue?.meta.next_cursor ?? undefined)">Muat berikutnya</UButton>
    </template>
  </section>
</template>
