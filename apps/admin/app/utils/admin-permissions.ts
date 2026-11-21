import type { AdminSession } from '../../shared/types/admin-session'

export function hasAdminPermission(session: AdminSession | null, permission: string): boolean {
  return session?.authenticated === true
    && (session.is_admin === true || session.permissions.includes(permission))
}

export function validAdminReason(reason: string, minimum = 1): boolean {
  const length = Array.from(reason.trim()).length
  return length >= minimum && length <= 320
}

export function adminApiError(error: unknown): string {
  const status = (error as { statusCode?: number } | null)?.statusCode
  if (status === 403) return '403 — Tidak ada akses. Izin Anda tidak mencukupi atau telah dicabut.'
  if (status === 404) return 'Data tidak ditemukan. Periksa ID publik staf.'
  if (status === 401 || status === 419) return 'Sesi kedaluwarsa. Muat ulang atau masuk kembali.'
  if (status === 422) return 'Data ditolak. Periksa nama permission, role, cakupan, dan alasan.'
  if (status === 409) return 'Permintaan sedang diproses atau kunci idempotensi telah digunakan. Muat ulang data.'
  return 'Permintaan gagal. Silakan coba lagi.'
}
