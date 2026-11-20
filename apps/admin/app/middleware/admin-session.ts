import type { AdminSession } from '#shared/types/admin-session'

export default defineNuxtRouteMiddleware(async () => {
  const request = useRequestFetch()
  const session = useState<AdminSession | null>('admin-session', () => null)
  try {
    session.value = await request<AdminSession>('/internal/admin-session')
  } catch {
    session.value = null
    return navigateTo('/login', { redirectCode: 302 })
  }
})
