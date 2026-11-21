import type { AdminSession } from '#shared/types/admin-session'

export function useAdminAccess() {
  const session = useState<AdminSession | null>('admin-session', () => null)
  return { can: (permission: string) => hasAdminPermission(session.value, permission) }
}
