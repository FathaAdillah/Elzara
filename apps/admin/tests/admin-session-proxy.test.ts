import { test } from 'node:test'
import assert from 'node:assert/strict'

test('SSR forwards configured admin authority, never the incoming Host', async (t) => {
  const names = ['defineEventHandler', 'setResponseHeader', 'useRuntimeConfig', 'getCookie', '$fetch', 'createError']
  const descriptors = names.map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)] as const)
  t.after(() => {
    for (const [key, descriptor] of descriptors) {
      if (descriptor) Object.defineProperty(globalThis, key, descriptor)
      else Reflect.deleteProperty(globalThis, key)
    }
  })
  let cookie: string | undefined = 'encrypted-session'
  let failed = false
  const calls: { baseURL: string, headers: Record<string, string> }[] = []
  const globals = {
    defineEventHandler: (handler: unknown) => handler,
    setResponseHeader: () => {},
    useRuntimeConfig: () => ({ apiInternalOrigin: 'http://nginx:8080', adminOrigin: 'https://admin.example.test:8443', adminSessionPath: '/api/v1/admin/auth/session' }),
    getCookie: () => cookie,
    createError: (data: object) => Object.assign(new Error('HTTP error'), data),
    $fetch: async (_path: string, options: typeof calls[number]) => {
      calls.push(options)
      if (failed) throw { statusCode: 403 }
      return { authenticated: true, user: { user_id: 'operator', name: 'Operator' }, is_admin: false, permissions: [] }
    },
  }
  for (const [key, value] of Object.entries(globals)) Object.defineProperty(globalThis, key, { configurable: true, value })
  const { default: handler } = await import('../server/routes/internal/admin-session.get.ts')
  const event = { context: { traceparent: 'trace' }, headers: { host: 'attacker.example.test' } }
  assert.equal((await handler(event as never)).authenticated, true)
  assert.equal(calls[0]?.baseURL, 'http://nginx:8080')
  assert.equal(calls[0]?.headers.host, 'admin.example.test:8443')
  assert.equal(calls[0]?.headers.origin, 'https://admin.example.test:8443')
  assert.equal(calls[0]?.headers.cookie, 'elzara_admin_session=encrypted-session')
  cookie = undefined
  await assert.rejects(handler(event as never), { statusCode: 401 })
  cookie = 'encrypted-session'
  failed = true
  await assert.rejects(handler(event as never), { statusCode: 401 })
})
