import { request as httpRequest } from 'node:http'
import { request as httpsRequest } from 'node:https'
import type { AdminSession } from '#shared/types/admin-session'

function requestSession(url: URL, headers: Record<string, string>): Promise<AdminSession> {
  return new Promise((resolve, reject) => {
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return reject(new Error('Unsupported API protocol'))
    const request = (url.protocol === 'https:' ? httpsRequest : httpRequest)(url, {
      method: 'GET', headers, signal: AbortSignal.timeout(5000),
    }, response => {
      response.on('error', reject)
      if (!response.statusCode || response.statusCode < 200 || response.statusCode >= 300) {
        response.resume()
        reject(Object.assign(new Error('Session request rejected'), { statusCode: response.statusCode }))
        return
      }
      response.setEncoding('utf8')
      let body = ''
      response.on('data', chunk => { body += chunk })
      response.on('end', () => {
        try { resolve(JSON.parse(body) as AdminSession) }
        catch (error) { reject(error) }
      })
    })
    request.on('error', reject)
    request.end()
  })
}

export default defineEventHandler(async (event): Promise<AdminSession> => {
  setResponseHeader(event, 'Cache-Control', 'no-store')
  const { apiInternalOrigin, adminOrigin, adminSessionPath } = useRuntimeConfig(event)
  if (!/^\/api\/v1\/admin\/[a-zA-Z0-9/_-]+$/.test(adminSessionPath)) {
    throw createError({ statusCode: 503, statusMessage: 'Admin session verification is unavailable' })
  }
  const sessionCookie = getCookie(event, 'elzara_admin_session')
  if (!sessionCookie) throw createError({ statusCode: 401 })
  try {
    const session = await requestSession(new URL(adminSessionPath, apiInternalOrigin), {
      cookie: `elzara_admin_session=${encodeURIComponent(sessionCookie)}`,
      origin: adminOrigin,
      host: new URL(adminOrigin).host,
      accept: 'application/json',
      traceparent: event.context.traceparent as string,
    })
    if (session?.authenticated === true && typeof session.user?.user_id === 'string'
      && typeof session.user.name === 'string' && typeof session.is_admin === 'boolean'
      && Array.isArray(session.permissions) && session.permissions.every(permission => typeof permission === 'string')) {
      return {
        authenticated: true,
        user: { user_id: session.user.user_id, name: session.user.name },
        permissions: session.permissions,
        is_admin: session.is_admin,
      }
    }
  } catch (error) {
    const status = (error as { statusCode?: number } | null)?.statusCode
    if (status === 401 || status === 403) throw createError({ statusCode: 401 })
  }
  throw createError({ statusCode: 503, statusMessage: 'Admin session verification is unavailable' })
})
