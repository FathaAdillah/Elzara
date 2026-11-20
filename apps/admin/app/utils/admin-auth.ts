import type { paths } from '@elzara/api-client'

export type AdminLogin = paths['/admin/auth/login']['post']['responses'][200]['content']['application/json']
export type Challenge = Extract<AdminLogin, { two_factor_required: true }>
type Verification = paths['/admin/auth/2fa/verify']['post']['responses'][200]['content']['application/json']

export async function adminAuth<T>(path: '/login' | '/2fa/verify' | '/logout', body?: Record<string, string>) {
  await $fetch('/sanctum/csrf-cookie', {
    query: { scope: 'admin', purpose: path === '/logout' ? undefined : 'login' },
    credentials: 'same-origin',
    cache: 'no-store',
    retry: 0,
  })
  const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice(11)
  if (!token) throw createError({ statusCode: 419 })
  return $fetch<T>(`/api/v1/admin/auth${path}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token) },
    body,
    retry: 0,
  })
}

export function verifyAdminChallenge(challengeId: string, code: string) {
  return adminAuth<Verification>('/2fa/verify', { challenge_id: challengeId, code })
}

export function authError(error: unknown, verifying: boolean) {
  const status = (error as { statusCode?: number } | null)?.statusCode
  switch (status) {
    case 401:
    case 403:
    case 422:
      return verifying
        ? 'Kode verifikasi ditolak atau tantangan sudah kedaluwarsa.'
        : 'Email atau kata sandi tidak valid, atau akses admin tidak tersedia.'
    case 419:
      return 'Sesi pengamanan kedaluwarsa atau belum tersedia. Coba lagi.'
    case 429:
      return 'Terlalu banyak percobaan. Tunggu sejenak sebelum mencoba lagi.'
    case 404:
    case 405:
    case 503:
      return 'Layanan autentikasi admin belum tersedia di lingkungan ini.'
    default:
      return 'Autentikasi belum dapat diproses. Coba lagi dalam beberapa saat.'
  }
}
