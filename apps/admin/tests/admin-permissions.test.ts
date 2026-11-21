import { test } from 'node:test'
import assert from 'node:assert/strict'
import { hasAdminPermission, adminApiError } from '../app/utils/admin-permissions.ts'

const session = { authenticated: true as const, user: { user_id: 'operator', name: 'Operator' }, permissions: ['setting.view'], is_admin: false }

test('permission checks deny absent sessions and empty grants', () => {
  assert.equal(hasAdminPermission(null, 'setting.view'), false)
  assert.equal(hasAdminPermission({ ...session, permissions: [] }, 'setting.view'), false)
})

test('permission checks use explicit grants, not role names or prefixes', () => {
  assert.equal(hasAdminPermission(session, 'setting.view'), true)
  assert.equal(hasAdminPermission(session, 'setting.update'), false)
  assert.equal(hasAdminPermission(session, 'setting'), false)
  assert.equal(hasAdminPermission({ ...session, permissions: [] }, 'setting.view'), false)
})

test('is_admin bypass enables actions without permission grants', () => {
  assert.equal(hasAdminPermission({ ...session, permissions: [], is_admin: true }, 'user.update'), true)
})

test('API errors clearly distinguish forbidden, missing, expired, invalid and unknown responses', () => {
  for (const statusCode of [403, 404, 401, 419, 422, 409]) {
    assert.notEqual(adminApiError({ statusCode }), adminApiError(null))
  }
  assert.match(adminApiError({ statusCode: 403 }), /403.*Tidak ada akses/)
  assert.equal(adminApiError(undefined), 'Permintaan gagal. Silakan coba lagi.')
})
