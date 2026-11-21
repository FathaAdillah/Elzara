<script setup lang="ts">
import type { BreadcrumbItem, DropdownMenuItem, NavigationMenuItem } from '@nuxt/ui'
import { brand } from '@elzara/ui/brand'
import type { AdminSession } from '#shared/types/admin-session'

const route = useRoute()
const preview = computed(() => route.meta.adminPreview === true)
const session = useState<AdminSession | null>('admin-session', () => null)
const operatorName = computed(() => preview.value ? 'Operator Demo' : session.value?.user.name || 'Operator')
const home = computed(() => preview.value ? '/preview' : '/')
const sections = [
  { id: 'ringkasan', label: 'Ringkasan', icon: 'i-lucide-chart-no-axes-column' },
  { id: 'tren', label: 'Tren pesanan', icon: 'i-lucide-trending-up' },
  { id: 'aktivitas', label: 'Aktivitas terbaru', icon: 'i-lucide-list-checks' },
]
const activeHash = ref('')
const openGroups = ref<string[]>([])
const selectedSection = computed(() => preview.value ? sections.find(section => `#${section.id}` === activeHash.value) : undefined)
const activeLink = 'font-semibold before:bg-(--admin-selected) text-highlighted'

const navigation = computed<NavigationMenuItem[]>(() => [
  {
    label: 'Dashboard', icon: 'i-lucide-layout-dashboard', to: home.value, exact: true, exactHash: true,
    ui: { link: selectedSection.value ? undefined : activeLink },
  },
  ...(preview.value ? [{
    value: 'operasional', label: 'Operasional', icon: 'i-lucide-package-check',
    active: Boolean(selectedSection.value),
    ui: { link: selectedSection.value ? activeLink : undefined },
    children: sections.map(section => ({
      label: section.label, icon: section.icon, to: `${home.value}#${section.id}`, exact: true, exactHash: true,
      ui: { link: selectedSection.value?.id === section.id ? activeLink : undefined },
    })),
  }] : []),
])

const breadcrumbs = computed<BreadcrumbItem[]>(() => selectedSection.value
  ? [{ label: 'Operasional' }, { label: selectedSection.value.label }]
  : [{ label: 'Dashboard' }])

onMounted(() => {
  watch(() => route.hash, async (hash, previousHash) => {
    activeHash.value = hash
    if (!preview.value) return
    const section = selectedSection.value
    if (section && !openGroups.value.includes('operasional')) openGroups.value = [...openGroups.value, 'operasional']
    if (section || previousHash) {
      await nextTick()
      document.getElementById(section?.id ?? 'admin-main')?.scrollIntoView({ block: 'start' })
    }
  }, { immediate: true, flush: 'post' })
})

const leaving = ref(false)
const logoutError = ref('')
const profileOpen = ref(false)
const logsOpen = ref(false)
const notificationRead = ref(false)
const toast = useToast()

function markNotificationRead() {
  notificationRead.value = true
  toast.add({ title: 'Notifikasi ditandai sudah dibaca', color: 'success', icon: 'i-lucide-check', type: 'background' })
}

function focusAccountMenu() {
  if (!profileOpen.value && !logsOpen.value) document.getElementById('admin-account-menu')?.focus()
}
const accountAvatar = {
  icon: 'i-lucide-user-round',
  class: 'shrink-0 bg-(--admin-selected) text-highlighted',
}
const accountMenu = computed<DropdownMenuItem[][]>(() => [
  [{ type: 'label', label: operatorName.value, avatar: { ...accountAvatar, size: 'sm' }, class: 'min-h-10 text-highlighted' }],
  [
    { label: 'Profil', icon: 'i-lucide-user-round', onSelect: () => { profileOpen.value = true } },
    { label: 'Log Viewer', icon: 'i-lucide-file-text', onSelect: () => { logsOpen.value = true } },
  ],
  [{
    label: 'Keluar', icon: 'i-lucide-log-out', color: 'error', disabled: leaving.value,
    onSelect: () => { void logout() },
  }],
])

async function logout() {
  if (preview.value) {
    await navigateTo('/login')
    return
  }
  leaving.value = true
  logoutError.value = ''
  try {
    await adminAuth<void>('/logout')
    clearNuxtState('admin-session')
    await navigateTo('/login', { replace: true })
  } catch {
    logoutError.value = 'Sesi belum berhasil ditutup. Coba lagi.'
  } finally {
    leaving.value = false
  }
}
</script>

<template>
  <UDashboardGroup unit="px" storage-key="elzara-admin-sidebar">
    <UDashboardSidebar
      collapsible auto-close :default-size="264" :min-size="200" :max-size="320" :collapsed-size="64"
      :ui="{ root: 'bg-(--admin-sidebar) border-default', content: 'max-w-[min(19rem,85vw)] bg-(--admin-sidebar)', header: 'h-18 border-b border-default px-4', body: 'px-3 pt-4', footer: 'px-3 pb-4' }"
    >
      <template #header="{ collapsed }">
        <NuxtLink :to="home" :aria-label="`${brand.name} Admin`" class="inline-flex min-w-0 items-center gap-3 text-highlighted">
          <img src="/brand/elzara-mark.png" alt="" width="36" height="36" fetchpriority="high" class="size-9 shrink-0">
          <span v-if="!collapsed" class="truncate text-lg font-semibold tracking-tight">{{ brand.name }} <span class="text-sm font-medium text-(--admin-ink)">Admin</span></span>
        </NuxtLink>
      </template>

      <template #default="{ collapsed }">
        <UNavigationMenu
          v-model="openGroups" :items="navigation" :collapsed="collapsed" orientation="vertical" type="multiple" tooltip
          aria-label="Navigasi admin"
          :popover="{ mode: 'click', content: { side: 'right', align: 'start', sideOffset: 8 } }"
          :ui="{
            link: 'min-h-10 rounded-lg text-(--admin-ink)',
            linkLeadingIcon: 'text-(--admin-ink)',
            linkLabel: collapsed ? 'block sr-only' : undefined,
            linkTrailingIcon: 'text-(--admin-ink)',
            childList: collapsed ? undefined : 'ms-4 border-s border-(--admin-selected) ps-2',
          }"
        />
      </template>

      <template #footer="{ collapsed }">
        <div class="w-full border-t border-default pt-3">
          <UDropdownMenu
            :items="accountMenu" :content="{ side: 'top', align: collapsed ? 'start' : 'center', sideOffset: 8 }"
            :ui="{ content: collapsed ? 'w-56' : 'w-(--reka-dropdown-menu-trigger-width)' }"
          >
            <UButton
              id="admin-account-menu"
              type="button" color="neutral" variant="soft" block :loading="leaving"
              class="bg-(--admin-tint) hover:bg-muted data-[state=open]:bg-muted"
              :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
              :aria-label="`Menu akun ${operatorName}`"
              :ui="{ base: collapsed ? 'min-h-10 justify-center p-0' : 'min-h-10 justify-start gap-2.5', trailingIcon: 'ml-auto' }"
            >
              <UAvatar v-bind="accountAvatar" size="sm" aria-hidden="true" />
              <span v-if="!collapsed" class="min-w-0 truncate">{{ operatorName }}</span>
            </UButton>
          </UDropdownMenu>
        </div>
      </template>
    </UDashboardSidebar>

    <UDashboardPanel id="operations" :ui="{ root: 'bg-default', body: 'p-5 sm:p-7' }">
      <template #header>
        <UDashboardNavbar :as="{ title: 'div' }" :ui="{ root: 'h-18 border-default bg-default', left: 'min-w-0', title: 'min-w-0' }">
          <template #toggle>
            <UDashboardSidebarCollapse class="hidden lg:inline-flex" aria-label="Ciutkan atau buka navigasi" />
            <UDashboardSidebarToggle class="lg:hidden" aria-label="Buka navigasi" />
          </template>
          <template #title>
            <UBreadcrumb :items="breadcrumbs" :ui="{ list: 'min-w-0', item: 'min-w-0', link: 'truncate' }" />
          </template>
          <template #right>
            <UColorModeButton aria-label="Ganti tema terang atau gelap" size="lg" />
            <UPopover :content="{ align: 'end', sideOffset: 8 }" :ui="{ content: 'w-72 max-w-[calc(100vw-2rem)]' }">
              <UButton
                type="button" color="neutral" variant="ghost" size="lg" icon="i-lucide-bell" class="relative"
                :aria-label="preview && !notificationRead ? 'Notifikasi, 1 belum dibaca' : 'Notifikasi'"
              >
                <span v-if="preview && !notificationRead" class="absolute end-1.5 top-1.5 size-2 rounded-full bg-primary ring-2 ring-default" aria-hidden="true" />
              </UButton>
              <template #content>
                <div class="space-y-3 p-4">
                  <h2 class="font-semibold text-highlighted">Notifikasi</h2>
                  <template v-if="preview">
                    <p class="text-sm text-highlighted">Stok menipis</p>
                    <p class="text-sm text-muted">Contoh · 6 produk perlu diperiksa.</p>
                    <UButton variant="soft" size="sm" :aria-disabled="notificationRead" :label="notificationRead ? 'Sudah dibaca' : 'Tandai sudah dibaca'" @click="!notificationRead && markNotificationRead()" />
                  </template>
                  <p v-else class="text-sm text-muted">Notifikasi admin belum tersedia.</p>
                </div>
              </template>
            </UPopover>
          </template>
        </UDashboardNavbar>
      </template>

      <template #body>
        <main id="admin-main" class="mx-auto w-full max-w-[92rem] scroll-mt-5 sm:scroll-mt-7">
          <UAlert v-if="logoutError" class="mb-5" color="error" variant="subtle" :description="logoutError" role="alert" />
          <slot />
        </main>
      </template>
    </UDashboardPanel>

    <UModal v-model:open="profileOpen" title="Profil" description="Informasi akun operator." @after:leave="focusAccountMenu">
      <template #body>
        <div class="flex items-center gap-3">
          <UAvatar v-bind="accountAvatar" size="lg" aria-hidden="true" />
          <dl class="min-w-0">
            <dt class="text-sm text-muted">Nama</dt>
            <dd class="break-words font-medium text-highlighted">{{ operatorName }}</dd>
          </dl>
        </div>
      </template>
    </UModal>

    <UModal v-model:open="logsOpen" title="Log Viewer" description="Riwayat aktivitas admin." @after:leave="focusAccountMenu">
      <template #body>
        <ul v-if="preview" class="divide-y divide-default text-sm" aria-label="Contoh log aktivitas">
          <li class="flex items-center justify-between gap-3 py-2">
            <span>Operator Demo membuka dashboard</span>
            <UBadge color="neutral" variant="soft" label="Contoh" />
          </li>
        </ul>
        <p v-else class="text-sm text-muted">Log aktivitas admin belum tersedia.</p>
      </template>
    </UModal>
  </UDashboardGroup>
</template>
