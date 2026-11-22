import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createServer, type IncomingHttpHeaders } from 'node:http'
import { once } from 'node:events'

test('SSR forwards configured admin authority to a real internal HTTP server', async (t) => {
  const names = ['defineEventHandler', 'setResponseHeader', 'useRuntimeConfig', 'getCookie', 'createError']
  const descriptors = names.map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)] as const)
  t.after(() => {
    for (const [key, descriptor] of descriptors) {
      if (descriptor) Object.defineProperty(globalThis, key, descriptor)
      else Reflect.deleteProperty(globalThis, key)
    }
  })
  let status = 200
  let payload: unknown = { authenticated: true, user: { user_id: 'operator', name: 'Operator' }, is_admin: false, permissions: [] }
  const calls: { path: string | undefined, headers: IncomingHttpHeaders }[] = []
  const server = createServer((request, response) => {
    calls.push({ path: request.url, headers: request.headers })
    response.writeHead(status, { 'Content-Type': 'application/json', Location: '/redirect-must-not-be-followed' })
    response.end(JSON.stringify(payload))
  })
  server.listen(0, '127.0.0.1')
  await once(server, 'listening')
  t.after(() => new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve())))
  const address = server.address()
  assert.ok(address && typeof address === 'object')
  let cookie: string | undefined = 'encrypted-session'
  const globals = {
    defineEventHandler: (handler: unknown) => handler,
    setResponseHeader: () => {},
    useRuntimeConfig: () => ({ apiInternalOrigin: `http://127.0.0.1:${address.port}`, adminOrigin: 'https://admin.example.test:8443', adminSessionPath: '/api/v1/admin/auth/session' }),
    getCookie: () => cookie,
    createError: (data: object) => Object.assign(new Error('HTTP error'), data),
  }
  for (const [key, value] of Object.entries(globals)) Object.defineProperty(globalThis, key, { configurable: true, value })
  const { default: handler } = await import('../server/routes/internal/admin-session.get.ts')
  const event = { context: { traceparent: 'trace' }, headers: { host: 'attacker.example.test' } }
  assert.equal((await handler(event as never)).authenticated, true)
  assert.equal(calls[0]?.path, '/api/v1/admin/auth/session')
  assert.equal(calls[0]?.headers.host, 'admin.example.test:8443')
  assert.equal(calls[0]?.headers.origin, 'https://admin.example.test:8443')
  assert.equal(calls[0]?.headers.cookie, 'elzara_admin_session=encrypted-session')
  assert.equal(calls[0]?.headers.traceparent, 'trace')
  assert.equal(calls[0]?.headers.accept, 'application/json')
  cookie = undefined
  await assert.rejects(handler(event as never), { statusCode: 401 })
  assert.equal(calls.length, 1)
  cookie = 'encrypted-session'
  for (const code of [401, 403, 302, 500]) {
    status = code
    const before = calls.length
    await assert.rejects(handler(event as never), { statusCode: code === 401 || code === 403 ? 401 : 503 })
    assert.equal(calls.length, before + 1)
  }
  status = 200
  for (const invalid of [null, {}, { authenticated: true, user: {} }]) {
    payload = invalid
    await assert.rejects(handler(event as never), { statusCode: 503 })
  }
})
