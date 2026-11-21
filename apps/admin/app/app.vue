<script setup lang="ts">
import { id } from '@nuxt/ui/locale'
</script>

<template>
  <UApp :locale="id" :toaster="{ progress: false }">
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>
