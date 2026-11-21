<script setup lang="ts">
import type { StaffAccess, RoleAssignment, PermissionGrant, AccessReason } from '~/utils/admin-api'

definePageMeta({ layout: 'admin', middleware: 'admin-session', key: route => route.fullPath })
useSeoMeta({ title: 'Kelola akses staf', robots: 'noindex, nofollow' })
const route = useRoute()
const { can } = useAdminAccess()
const publicId = computed(() => String(route.params.publicId))
const base = computed(() => `/users/${encodeURIComponent(publicId.value)}`)
const { data: access, error, status, refresh } = await useAsyncData(
  () => `staff-access:${publicId.value}`,
  () => can('user.view') ? adminRead<StaffAccess>(`${base.value}/access`) : Promise.resolve(null),
  { server: false },
)
const reason = ref('')
const permission = ref('')
const role = ref('')
const scopeType = ref('global')
const scopeId = ref<number>()
const busy = ref(false)
const mutationError = ref('')
const notice = ref('')
const scopeOptions = ['global', 'batch', 'category', 'warehouse']
const validReason = computed(() => Boolean(reason.value.trim()) && reason.value.length <= 320)
const validScope = computed(() => scopeType.value === 'global'
  || (Number.isSafeInteger(scopeId.value) && Number(scopeId.value) > 0))

async function mutate(path: string, method: 'POST' | 'DELETE', body: PermissionGrant | RoleAssignment | AccessReason) {
  if (!can('user.update') || !validReason.value || busy.value) return
  busy.value = true
  mutationError.value = ''
  notice.value = ''
  try {
    access.value = await adminMutate<StaffAccess>(`${base.value}${path}`, method, body)
    reason.value = ''
    notice.value = 'Akses staf berhasil diperbarui dan dicatat dalam audit.'
  } catch (cause) {
    mutationError.value = adminApiError(cause)
  } finally {
    busy.value = false
  }
}

function assignRole() {
  if (!role.value.trim() || !validScope.value) return
  const scoped = scopeType.value !== 'global'
  return mutate('/roles', 'POST', {
    role: role.value.trim(), reason: reason.value.trim(),
    scope_type: scoped ? scopeType.value as 'batch' | 'category' | 'warehouse' : null,
    scope_id: scoped ? Number(scopeId.value) : null,
  })
}
</script>

<template>
  <div class="space-y-6 p-6">
    <UButton to="/staff" variant="link" icon="i-lucide-arrow-left">Akses staf</UButton>
    <h1 class="text-2xl font-semibold">Kelola akses staf</h1>
    <p class="break-all text-sm text-muted">ID publik: {{ publicId }}</p>
    <AdminNoAccess v-if="!can('user.view')" />
    <template v-else>
      <p v-if="status === 'pending'" role="status">Memuat akses…</p>
      <UAlert v-else-if="error" color="error" :title="adminApiError(error)" />
      <template v-else-if="access">
        <UAlert v-if="access.is_admin" color="warning" title="Bypass administrator aktif" description="Staf ini dapat melewati pemeriksaan permission admin. Setiap bypass dicatat dalam audit." />
        <UAlert v-if="mutationError" color="error" :title="mutationError" />
        <UAlert v-if="notice" color="success" :title="notice" />
        <UButton variant="outline" :disabled="busy" @click="refresh()">Muat ulang akses</UButton>
        <UCard v-if="can('user.update')">
          <UFormField label="Alasan perubahan" required help="Wajib untuk setiap pemberian maupun pencabutan akses; maksimal 320 karakter.">
            <UTextarea v-model="reason" class="w-full" required :maxlength="320" :disabled="busy" />
          </UFormField>
        </UCard>
        <p v-else class="text-sm text-muted">Hanya baca — perubahan memerlukan user.update.</p>
        <div class="grid gap-6 lg:grid-cols-2">
          <UCard>
            <template #header><h2 class="font-semibold">Role dan cakupan</h2></template>
            <ul v-if="access.roles.length" class="space-y-4">
              <li v-for="assignment in access.roles" :key="assignment.role" class="space-y-2">
                <p class="font-medium">{{ assignment.role }}</p>
                <p v-if="!assignment.scopes.length" class="text-sm text-muted">Global</p>
                <ul v-else class="text-sm text-muted">
                  <li v-for="(scope, index) in assignment.scopes" :key="index">{{ scope.scope_type ? `${scope.scope_type}: ${scope.scope_id}` : 'Global' }}</li>
                </ul>
                <UButton v-if="can('user.update')" color="error" variant="soft" :loading="busy" :disabled="!validReason || busy" :aria-label="`Cabut role ${assignment.role}`" @click="mutate(`/roles/${encodeURIComponent(assignment.role)}`, 'DELETE', { reason: reason.trim() })">Cabut role dan seluruh cakupan</UButton>
              </li>
            </ul>
            <p v-else class="text-sm text-muted">Belum ada role.</p>
            <form v-if="can('user.update')" class="mt-6 space-y-4" @submit.prevent="assignRole">
              <UFormField label="Nama role" required><UInput v-model="role" class="w-full" required :maxlength="48" :disabled="busy" placeholder="Contoh: finance" /></UFormField>
              <UFormField label="Jenis cakupan"><USelect v-model="scopeType" :items="scopeOptions" :disabled="busy" /></UFormField>
              <UFormField v-if="scopeType !== 'global'" label="ID cakupan" required><UInput v-model.number="scopeId" type="number" :min="1" step="1" required :disabled="busy" /></UFormField>
              <UButton type="submit" :loading="busy" :disabled="!validReason || !validScope || !role.trim() || busy">Tetapkan role</UButton>
            </form>
          </UCard>
          <UCard>
            <template #header><h2 class="font-semibold">Permission langsung</h2></template>
            <ul v-if="access.direct_permissions.length" class="space-y-3">
              <li v-for="name in access.direct_permissions" :key="name" class="flex flex-wrap items-center justify-between gap-2">
                <code class="break-all text-sm">{{ name }}</code>
                <UButton v-if="can('user.update')" color="error" variant="soft" :loading="busy" :disabled="!validReason || busy" :aria-label="`Cabut permission ${name}`" @click="mutate(`/permissions/${encodeURIComponent(name)}`, 'DELETE', { reason: reason.trim() })">Cabut</UButton>
              </li>
            </ul>
            <p v-else class="text-sm text-muted">Belum ada permission langsung.</p>
            <form v-if="can('user.update')" class="mt-6 space-y-4" @submit.prevent="mutate('/permissions', 'POST', { permission: permission.trim(), reason: reason.trim() })">
              <UFormField label="Nama permission" required><UInput v-model="permission" class="w-full" required :maxlength="64" :disabled="busy" placeholder="Contoh: setting.view" /></UFormField>
              <UButton type="submit" :loading="busy" :disabled="!validReason || !permission.trim() || busy">Berikan permission</UButton>
            </form>
          </UCard>
        </div>
        <UCard>
          <template #header><h2 class="font-semibold">Permission efektif</h2></template>
          <ul v-if="access.permissions.length" class="flex flex-wrap gap-2">
            <li v-for="name in access.permissions" :key="name"><UBadge color="neutral" variant="soft">{{ name }}</UBadge></li>
          </ul>
          <p v-else class="text-sm text-muted">Tidak ada permission efektif dari role atau grant langsung.</p>
        </UCard>
      </template>
    </template>
  </div>
</template>
