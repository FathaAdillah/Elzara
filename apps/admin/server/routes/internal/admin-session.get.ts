import type { AdminSession } from '#shared/types/admin-session'

export default defineEventHandler(async (event): Promise<AdminSession> => {
  setResponseHeader(event, 'Cache-Control', 'no-store')
  const { apiInternalOrigin, adminOrigin, adminSessionPath } = useRuntimeConfig(event)
  if (!/^\/api\/v1\/admin\/[a-zA-Z0-9/_-]+$/.test(adminSessionPath)) {
    throw createError({ statusCode: 503, statusMessage: 'Admin session verification is unavailable' })
  }
  const sessionCookie = getCookie(event, 'elzara_admin_session')
  if (!sessionCookie) throw createError({ statusCode: 401 })
  try {
    const session = await $fetch<AdminSession>(adminSessionPath, {
      baseURL: apiInternalOrigin,
      headers: {
        cookie: `elzara_admin_session=${encodeURIComponent(sessionCookie)}`,
        origin: adminOrigin,
        accept: 'application/json',
      },
      redirect: 'error',
      retry: 0,
      timeout: 5000,
    })
    if (session?.authenticated === true && typeof session.user?.user_id === 'string'
      && typeof session.user.name === 'string') {
      return { authenticated: true, user: { user_id: session.user.user_id, name: session.user.name } }
    }
  } catch (error) {
    const status = (error as { statusCode?: number } | null)?.statusCode
    if (status === 401 || status === 403) throw createError({ statusCode: 401 })
  }
  throw createError({ statusCode: 503, statusMessage: 'Admin session verification is unavailable' })
})
