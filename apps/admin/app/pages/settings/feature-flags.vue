<script setup lang="ts">
import type { FeatureFlags } from '~/utils/admin-api'

definePageMeta({ layout: 'admin', middleware: 'admin-session' })
useSeoMeta({ title: 'Feature flags', robots: 'noindex, nofollow' })
const { can } = useAdminAccess()
const { data: flags, error, status, refresh } = await useAsyncData('admin-feature-flags',
  () => can('setting.view') ? adminRead<FeatureFlags>('/feature-flags') : Promise.resolve(null),
  { server: false },
)
const reason = ref('')
const busy = ref(false)
const mutationError = ref('')
const notice = ref('')

async function toggleFlag(code: string, enabled: boolean) {
  if (!can('setting.update') || !reason.value.trim() || reason.value.length > 320 || busy.value) return
  busy.value = true
  notice.value = ''
  mutationError.value = ''
  try {
    flags.value = await adminMutate<FeatureFlags>('/feature-flags', 'PATCH', {
      flags: [{ code, default_enabled: !enabled }], reason: reason.value.trim(),
    })
    reason.value = ''
    notice.value = 'Feature flag berhasil diperbarui.'
  } catch (cause) {
    mutationError.value = adminApiError(cause)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-6 p-6">
    <h1 class="text-2xl font-semibold">Feature flags</h1>
    <AdminNoAccess v-if="!can('setting.view')" />
    <template v-else>
      <p v-if="status === 'pending'" role="status">Memuat pengaturan…</p>
      <UAlert v-else-if="error" color="error" :title="adminApiError(error)" />
      <template v-else-if="flags">
        <UAlert v-if="mutationError" color="error" :title="mutationError" />
        <UAlert v-if="notice" color="success" :title="notice" />
        <UButton variant="outline" :disabled="busy" @click="refresh()">Muat ulang</UButton>
        <UFormField v-if="can('setting.update')" label="Alasan perubahan" required>
          <UTextarea v-model="reason" class="w-full" :maxlength="320" :disabled="busy" />
        </UFormField>
        <p v-else class="text-sm text-muted">Hanya baca — perubahan memerlukan setting.update.</p>
        <p v-if="!flags.data.length" class="text-sm text-muted">Belum ada feature flag.</p>
        <UCard v-for="flag in flags.data" :key="flag.code">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
              <h2 class="font-semibold">{{ flag.code }}</h2>
              <p class="text-sm text-muted">{{ flag.description }}</p>
              <UBadge :color="flag.default_enabled ? 'success' : 'neutral'">Default: {{ flag.default_enabled ? 'Aktif' : 'Nonaktif' }}</UBadge>
              <p v-if="flag.requires_restart" class="text-sm text-muted">Memerlukan restart.</p>
              <ul v-if="flag.overrides.length" class="text-sm text-muted">
                <li v-for="override in flag.overrides" :key="override.env">{{ override.env }}: {{ override.enabled ? 'Aktif' : 'Nonaktif' }} ({{ override.rollout_percent }}%)</li>
              </ul>
            </div>
            <UButton v-if="can('setting.update')" :loading="busy" :disabled="!reason.trim() || busy" @click="toggleFlag(flag.code, flag.default_enabled)">Ubah default menjadi {{ flag.default_enabled ? 'nonaktif' : 'aktif' }}</UButton>
          </div>
        </UCard>
      </template>
    </template>
  </div>
</template>
