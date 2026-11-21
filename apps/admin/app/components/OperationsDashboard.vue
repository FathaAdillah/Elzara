<script setup lang="ts">
import type { AdminSession } from '#shared/types/admin-session'

const props = defineProps<{ preview?: boolean, operator?: AdminSession['user'] }>()
const periods = ['7 hari', '14 hari'] as const
const period = ref<(typeof periods)[number]>('7 hari')
const operatorName = computed(() => props.preview ? 'Operator Demo' : props.operator?.name || 'Operator')
const profileInitials = computed(() => operatorName.value.trim().split(/\s+/).slice(0, 2)
  .map(word => Array.from(word)[0]).join('').toLocaleUpperCase('id-ID'))
const today = useState('admin-current-date', () => new Intl.DateTimeFormat('id-ID', {
  dateStyle: 'full', timeZone: 'Asia/Jakarta',
}).format())

const activity = [
  { id: 'DEMO-1042', name: 'Paket koleksi', status: 'Dikemas' },
  { id: 'DEMO-1041', name: 'Aksesori katalog', status: 'Dikirim' },
  { id: 'DEMO-1040', name: 'Item pre-order', status: 'Menunggu' },
]
const dailyOrders = [5, 8, 6, 10, 8, 12, 9, 10, 16, 13, 19, 16, 23, 15]
const chartData = computed(() => dailyOrders.slice(period.value === '7 hari' ? -7 : -14)
  .map((count, index) => ({ day: String(index + 1), count })))
const orders = computed(() => chartData.value.reduce((total, { count }) => total + count, 0))
const metrics = computed(() => [
  { name: 'Pesanan', value: orders.value, icon: 'i-lucide-shopping-bag' },
  { name: 'Perlu diproses', value: period.value === '7 hari' ? 18 : 29, icon: 'i-lucide-package-check' },
  { name: 'Produk aktif', value: 86, icon: 'i-lucide-box' },
  { name: 'Stok menipis', value: 6, icon: 'i-lucide-triangle-alert' },
])
</script>

<template>
  <div class="space-y-5 sm:space-y-6">
    <UCard
      as="header"
      variant="soft"
      class="rounded-2xl bg-linear-to-br from-(--admin-tint) via-muted to-default ring ring-default"
      :ui="{ body: 'p-5 sm:p-8' }"
    >
      <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
          <h1 class="text-3xl font-semibold tracking-tight text-highlighted sm:text-4xl">Dashboard</h1>
          <p class="mt-2 break-words text-sm text-muted sm:text-base">Selamat datang, {{ operatorName }}.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <UBadge v-if="preview" color="primary" variant="subtle" label="Data contoh" />
          <UBadge
            color="neutral" variant="outline" size="md" icon="i-lucide-calendar-days" :label="today"
            class="max-w-full rounded-lg bg-default px-2.5 py-1.5 text-muted ring-default"
            :ui="{ label: 'whitespace-normal' }"
          />
        </div>
      </div>
    </UCard>

    <template v-if="preview">
      <section id="ringkasan" aria-labelledby="ringkasan-title" class="scroll-mt-5 space-y-4 sm:scroll-mt-7">
        <h2 id="ringkasan-title" class="flex items-center gap-2 text-lg font-semibold text-highlighted">
          <UIcon name="i-lucide-chart-no-axes-column" class="size-5 text-(--admin-ink)" />
          Ringkasan operasional
        </h2>
        <div class="grid grid-cols-1 gap-3 min-[360px]:grid-cols-2 xl:grid-cols-4 xl:gap-gutter">
          <UCard v-for="metric in metrics" :key="metric.name" class="rounded-xl border-s-2 border-elzara-300" :ui="{ body: 'min-h-28 p-4 sm:p-5' }">
            <dl>
              <dt class="flex items-start justify-between gap-2 text-sm text-muted">
                {{ metric.name }}
                <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-(--admin-tint) text-(--admin-ink)" aria-hidden="true">
                  <UIcon :name="metric.icon" class="size-4" />
                </span>
              </dt>
              <dd class="mt-2 text-3xl font-semibold tabular-nums tracking-tight text-highlighted">{{ metric.value }}</dd>
            </dl>
          </UCard>
        </div>
      </section>

      <UCard id="tren" class="scroll-mt-5 sm:scroll-mt-7" :ui="{ header: 'p-5 sm:px-6', body: 'p-5 sm:px-6' }">
        <template #header>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="flex items-center gap-2 font-semibold text-highlighted">
              <UIcon name="i-lucide-trending-up" class="size-5 text-(--admin-ink)" />
              Tren pesanan
            </h2>
            <USelect v-model="period" :items="[...periods]" icon="i-lucide-calendar-days" aria-label="Periode tren pesanan" class="w-32" />
          </div>
        </template>
        <figure>
          <div class="min-h-[285px] min-w-0" role="group" aria-label="Tren pesanan harian">
            <ClientOnly>
              <LazyOperationsTrendChart :data="chartData" />
              <template #fallback>
                <USkeleton class="h-[285px] w-full" />
              </template>
            </ClientOnly>
          </div>
          <figcaption class="pt-2 text-sm text-muted" aria-live="polite">{{ orders }} pesanan dalam {{ period }} terakhir.</figcaption>
        </figure>
      </UCard>

      <UCard id="aktivitas" class="scroll-mt-5 sm:scroll-mt-7" :ui="{ header: 'p-5 sm:px-6', body: 'px-5 py-0 sm:px-6' }">
        <template #header>
          <h2 class="text-base font-semibold text-highlighted">Aktivitas terbaru</h2>
        </template>
        <ul class="divide-y divide-default">
          <li v-for="item in activity" :key="item.id" class="flex items-center justify-between gap-3 py-3.5">
            <div>
              <p class="text-sm font-medium">{{ item.name }}</p>
              <p class="mt-0.5 text-xs text-muted">{{ item.id }}</p>
            </div>
            <UBadge variant="subtle" color="neutral" :label="item.status" />
          </li>
        </ul>
      </UCard>
    </template>

    <div v-else class="grid gap-4 xl:grid-cols-[minmax(0,2.15fr)_minmax(0,1fr)] xl:gap-6">
      <UCard
        as="section" aria-labelledby="ringkasan-title" class="min-w-0 rounded-2xl"
        :ui="{ header: 'px-5 py-4 sm:px-6', body: 'p-3 sm:p-4' }"
      >
        <template #header>
          <h2 id="ringkasan-title" class="flex items-center gap-2.5 text-base font-semibold text-highlighted">
            <UIcon name="i-lucide-chart-no-axes-column" class="size-5 shrink-0 text-(--admin-ink)" aria-hidden="true" />
            Ringkasan operasional
          </h2>
        </template>
        <UEmpty
          :as="{ title: 'h3' }" icon="i-lucide-chart-no-axes-combined" variant="soft"
          title="Data operasional belum tersedia"
          description="Metrik dan tren pesanan akan muncul setelah sumber data operasional terhubung."
          :ui="{
            root: 'min-h-56 bg-muted/65 px-4 py-8 sm:px-6',
            avatar: 'rounded-xl bg-(--admin-tint) text-(--admin-ink)',
          }"
        />
      </UCard>

      <UCard
        v-if="operator" as="section" aria-labelledby="akun-operator-title" class="min-w-0 self-start rounded-2xl"
        :ui="{ header: 'px-5 py-4 sm:px-6', body: 'p-5 sm:p-6', footer: 'px-5 py-4 sm:px-6' }"
      >
        <template #header>
          <h2 id="akun-operator-title" class="flex items-center gap-2.5 text-base font-semibold text-highlighted">
            <UIcon name="i-lucide-user-round" class="size-5 shrink-0 text-(--admin-ink)" aria-hidden="true" />
            Akun operator
          </h2>
        </template>
        <div class="flex items-center gap-3">
          <UAvatar :text="profileInitials" size="lg" class="shrink-0 bg-(--admin-tint) text-(--admin-ink)" aria-hidden="true" />
          <p class="min-w-0 break-words text-base font-semibold text-highlighted">{{ operatorName }}</p>
        </div>
        <template #footer>
          <UBadge color="success" variant="subtle" icon="i-lucide-shield-check" label="Sesi aktif" class="text-highlighted" />
        </template>
      </UCard>
    </div>
  </div>
</template>
