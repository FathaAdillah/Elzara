<script setup lang="ts">
definePageMeta({ layout: 'admin', adminPreview: true })

if (String(useRuntimeConfig().public.previewEnabled) !== 'true') {
  throw createError({ statusCode: 404, statusMessage: 'Halaman tidak ditemukan' })
}
useSeoMeta({ title: 'Pratinjau dashboard', robots: 'noindex, nofollow' })
</script>

<template>
  <OperationsDashboard preview />
</template>
