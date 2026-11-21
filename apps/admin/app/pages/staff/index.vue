<script setup lang="ts">
definePageMeta({ layout: 'admin', middleware: 'admin-session' })
useSeoMeta({ title: 'Akses staf', robots: 'noindex, nofollow' })
const { can } = useAdminAccess()
const publicId = ref('')

function openAccess() {
  if (can('user.view') && publicId.value.trim()) return navigateTo(`/staff/${encodeURIComponent(publicId.value.trim())}/access`)
}
</script>

<template>
  <div class="space-y-6 p-6">
    <h1 class="text-2xl font-semibold">Akses staf</h1>
    <AdminNoAccess v-if="!can('user.view')" />
    <UCard v-else>
      <form class="space-y-4" @submit.prevent="openAccess">
        <UFormField label="ID publik staf" required help="Gunakan users.public_id, bukan ID numerik internal.">
          <UInput v-model="publicId" class="w-full" required />
        </UFormField>
        <UButton type="submit" :disabled="!publicId.trim()">Lihat akses</UButton>
      </form>
    </UCard>
  </div>
</template>
