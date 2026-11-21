import { test } from 'node:test'
import assert from 'node:assert/strict'
import { adminRead, adminMutate } from '../app/utils/admin-api.ts'

test('admin requests bootstrap CSRF, send JSON reasons and unique idempotency keys without retries', async (t) => {
  const descriptors = ['$fetch', 'document', 'createError'].map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)] as const)
  t.after(() => {
    for (const [key, descriptor] of descriptors) {
      if (descriptor) Object.defineProperty(globalThis, key, descriptor)
      else Reflect.deleteProperty(globalThis, key)
    }
  })
  const calls: { path: string, options: { headers?: Record<string, string>, method?: string, body?: unknown, retry: number, credentials: string, cache?: string, query?: unknown } }[] = []
  let cookie = 'XSRF-TOKEN=encrypted%2Btoken'
  let denied = false
  Object.defineProperty(globalThis, 'document', { configurable: true, value: { get cookie() { return cookie } } })
  Object.defineProperty(globalThis, 'createError', { configurable: true, value: (data: { statusCode: number }) => Object.assign(new Error('HTTP error'), data) })
  Object.defineProperty(globalThis, '$fetch', { configurable: true, value: async (path: string, options: typeof calls[number]['options']) => {
    calls.push({ path, options })
    if (denied) throw Object.assign(new Error('Forbidden'), { statusCode: 403 })
    return { permissions: [] }
  } })

  assert.deepEqual(await adminRead('/users/public-id/access'), { permissions: [] })
  assert.equal(calls[0]?.path, '/api/v1/admin/users/public-id/access')
  assert.equal(calls[0]?.options.cache, 'no-store')
  calls.length = 0
  for (const method of ['POST', 'DELETE', 'PATCH'] as const) {
    await adminMutate('/users/public-id/permissions', method, { reason: 'Audit reason' })
  }
  const keys = new Set<string>()
  for (let index = 0; index < calls.length; index += 2) {
    assert.equal(calls[index]?.path, '/sanctum/csrf-cookie')
    assert.deepEqual(calls[index]?.options.query, { scope: 'admin' })
    const mutation = calls[index + 1]
    assert.equal(mutation?.path, '/api/v1/admin/users/public-id/permissions')
    assert.deepEqual(mutation?.options.body, { reason: 'Audit reason' })
    assert.equal(mutation?.options.headers?.['X-XSRF-TOKEN'], 'encrypted+token')
    const key = mutation?.options.headers?.['Idempotency-Key']
    assert.ok(key)
    assert.match(key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    keys.add(key)
    assert.equal(mutation?.options.credentials, 'same-origin')
    assert.equal(mutation?.options.retry, 0)
  }
  assert.equal(keys.size, 3)
  assert.deepEqual(calls.filter((_, index) => index % 2 === 1).map(call => call.options.method), ['POST', 'DELETE', 'PATCH'])

  cookie = ''
  calls.length = 0
  await assert.rejects(adminMutate('/users/public-id/permissions', 'POST', { reason: 'Audit reason' }), { statusCode: 419 })
  assert.equal(calls.length, 1)
  denied = true
  await assert.rejects(adminRead('/users/public-id/access'), { statusCode: 403 })
})
