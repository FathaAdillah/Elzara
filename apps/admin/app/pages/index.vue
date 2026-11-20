<script setup lang="ts">
import type { AdminSession } from '#shared/types/admin-session'

definePageMeta({ layout: 'admin', middleware: 'admin-session' })
const session = useState<AdminSession | null>('admin-session', () => null)
useSeoMeta({ title: 'Dashboard', robots: 'noindex, nofollow' })
</script>

<template>
  <OperationsDashboard v-if="session" :operator="session.user" />
</template>
