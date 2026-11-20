<script setup lang="ts">
import type { FormError } from '@nuxt/ui'
import type { AdminLogin, Challenge } from '~/utils/admin-auth'
import type { AdminSession } from '#shared/types/admin-session'

useSeoMeta({ title: 'Masuk', description: 'Masuk ke ruang kerja internal Elzara.' })

const credentials = reactive({ email: '', password: '' })
const verification = reactive({ code: '' })
const revealPassword = ref(false)
const challenge = ref<Challenge | null>(null)
const error = ref('')
const sending = ref(false)
const preview = String(useRuntimeConfig().public.previewEnabled) === 'true'

function validateLogin(): FormError[] {
  const errors: FormError[] = []
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(credentials.email.trim())) {
    errors.push({ name: 'email', message: 'Masukkan alamat email yang valid.' })
  }
  if (!credentials.password) errors.push({ name: 'password', message: 'Masukkan kata sandi Anda.' })
  return errors
}

function validateCode(): FormError[] {
  const length = verification.code.trim().length
  return length < 6 || length > 12 ? [{ name: 'code', message: 'Masukkan kode 6–12 karakter.' }] : []
}

function resetChallenge() {
  challenge.value = null
  verification.code = ''
  error.value = ''
}

async function finishLogin() {
  await $fetch<AdminSession>('/internal/admin-session', { retry: 0 })
  resetChallenge()
  await navigateTo('/')
}

async function submitLogin() {
  if (sending.value) return
  sending.value = true
  error.value = ''
  try {
    const response = await adminAuth<AdminLogin>('/login', {
      email: credentials.email.trim(),
      password: credentials.password,
    })
    credentials.password = ''
    revealPassword.value = false
    if (response?.two_factor_required === false && response.authenticated === true) {
      await finishLogin()
      return
    }
    if (response?.two_factor_required !== true || !response.challenge_id || !Number.isFinite(Date.parse(response.expires_at))) {
      throw createError({ statusCode: 503 })
    }
    challenge.value = response
  } catch (cause) {
    error.value = authError(cause, false)
  } finally {
    sending.value = false
  }
}

async function submitVerification() {
  if (sending.value || !challenge.value) return
  if (Date.parse(challenge.value.expires_at) <= Date.now()) {
    resetChallenge()
    error.value = 'Tantangan sudah kedaluwarsa. Masuk kembali untuk meminta kode baru.'
    return
  }
  sending.value = true
  error.value = ''
  try {
    const response = await verifyAdminChallenge(challenge.value.challenge_id, verification.code.trim())
    if (response?.authenticated !== true) throw createError({ statusCode: 503 })
    await finishLogin()
  } catch (cause) {
    error.value = authError(cause, true)
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <main class="grid min-h-dvh grid-cols-1 grid-rows-[auto_1fr] lg:grid-cols-[46%_minmax(0,1fr)] lg:grid-rows-1">
    <section class="login-brand relative isolate flex min-w-0 flex-col overflow-hidden px-7 py-7 lg:min-h-dvh lg:px-[10%] lg:py-11" aria-label="Elzara Admin">
      <NuxtLink to="/login" class="relative z-10 inline-flex w-fit items-center gap-3 rounded-xl bg-white px-4 py-2.5 text-elzara-900 shadow-sm">
        <img src="/brand/elzara-mark.png" alt="" width="32" height="32" fetchpriority="high" class="size-8 shrink-0">
        <span class="text-xl font-semibold tracking-tight">Elzara<span class="ml-1.5 text-sm font-medium text-elzara-700">Admin</span></span>
      </NuxtLink>

      <div class="relative z-10 my-auto hidden max-w-md py-12 lg:block">
        <h2 class="text-balance text-4xl font-bold leading-tight tracking-tight text-elzara-900">
          Ruang kerja operasional Elzara
        </h2>
        <p class="mt-4 max-w-sm text-base leading-relaxed text-elzara-800">
          Kelola pesanan, katalog, dan stok dari satu panel internal.
        </p>
      </div>
      <span class="brand-orbit orbit-one" aria-hidden="true" />
      <span class="brand-orbit orbit-two" aria-hidden="true" />
    </section>

    <section class="flex min-w-0 items-center justify-center bg-muted/55 px-5 py-10 sm:px-10 lg:py-16">
      <div class="w-full max-w-md">
        <UCard class="rounded-2xl shadow-xl shadow-elzara-950/5" :ui="{ body: 'p-6 sm:p-10' }">
          <header class="mb-8">
            <h1 class="text-2xl font-bold tracking-tight text-highlighted">
              {{ challenge ? 'Verifikasi dua langkah' : 'Masuk' }}
            </h1>
            <p class="mt-2 text-sm leading-relaxed text-muted">
              {{ challenge ? 'Gunakan kode dari aplikasi autentikator atau kode pemulihan.' : 'Masukkan email dan kata sandi akun operator Anda.' }}
            </p>
          </header>

          <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" :description="error" class="mb-5" role="alert" />

          <UForm v-if="!challenge" :state="credentials" :validate="validateLogin" :aria-busy="sending" class="flex flex-col gap-5" @submit="submitLogin">
            <UFormField label="Email" name="email" required>
              <UInput v-model="credentials.email" type="email" autocomplete="username" icon="i-lucide-mail" placeholder="nama@elzara.com" size="xl" class="w-full" maxlength="254" :disabled="sending" required />
            </UFormField>
            <UFormField label="Kata sandi" name="password" required>
              <UInput v-model="credentials.password" :type="revealPassword ? 'text' : 'password'" autocomplete="current-password" icon="i-lucide-lock" placeholder="Masukkan kata sandi" size="xl" class="w-full" maxlength="128" :disabled="sending" required>
                <template #trailing>
                  <UButton
                    type="button" variant="ghost" color="neutral" size="xs"
                    :icon="revealPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                    :aria-label="revealPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                    :aria-pressed="revealPassword"
                    :disabled="sending"
                    @click="revealPassword = !revealPassword"
                  />
                </template>
              </UInput>
            </UFormField>
            <UButton type="submit" size="xl" block :loading="sending" :label="sending ? 'Memproses…' : 'Masuk'" class="mt-2 font-semibold" />
          </UForm>
          <UForm v-else :state="verification" :validate="validateCode" :aria-busy="sending" class="space-y-5" @submit="submitVerification">
            <UFormField label="Kode verifikasi" name="code" required>
              <UInput v-model="verification.code" autocomplete="one-time-code" icon="i-lucide-key-round" size="lg" class="w-full" maxlength="12" :disabled="sending" autofocus required />
            </UFormField>
            <UButton type="submit" size="lg" block :loading="sending" :label="sending ? 'Memverifikasi…' : 'Verifikasi'" />
            <UButton type="button" variant="ghost" color="neutral" :disabled="sending" icon="i-lucide-arrow-left" label="Kembali ke login" @click="resetChallenge" />
          </UForm>
        </UCard>
        <div v-if="preview && !challenge" class="mt-4 text-center">
          <UButton to="/preview" variant="link" color="primary" icon="i-lucide-layout-dashboard" label="Lihat pratinjau dashboard" />
        </div>
      </div>
    </section>
  </main>
</template>

<style scoped>
.login-brand { background: linear-gradient(150deg, #e7eaf8 8%, #c0c9ee 55%, #a2aadb 100%); }
.brand-orbit { position: absolute; border-radius: 50%; pointer-events: none; }
.orbit-one { width: 28rem; height: 28rem; right: -14rem; bottom: -13rem; border: 3rem solid #ffffff36; }
.orbit-two { width: 20rem; height: 20rem; right: -6rem; bottom: -13rem; background: #898ac450; }
</style>
