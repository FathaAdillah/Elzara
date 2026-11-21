import { test } from 'node:test'
import assert from 'node:assert/strict'
import { adminRouteNavigation } from '../app/utils/admin-navigation.ts'

test('staff lookup and access pages select the staff item and corresponding breadcrumbs', () => {
  assert.deepEqual(adminRouteNavigation('/staff'), { section: 'staff', breadcrumbs: [{ label: 'Akses staf' }] })
  assert.deepEqual(adminRouteNavigation('/staff/public-id/access'), {
    section: 'staff', breadcrumbs: [{ label: 'Akses staf', to: '/staff' }, { label: 'Kelola akses' }],
  })
})

test('settings route selects feature flags instead of Dashboard', () => {
  assert.deepEqual(adminRouteNavigation('/settings/feature-flags/'), {
    section: 'feature-flags', breadcrumbs: [{ label: 'Pengaturan' }, { label: 'Feature flags' }],
  })
})

test('dashboard stays selected on home and preview, without matching similarly named routes', () => {
  for (const path of ['/', '/preview', '/staffing', '/settings/feature-flags-other']) {
    assert.deepEqual(adminRouteNavigation(path), { section: 'dashboard', breadcrumbs: [{ label: 'Dashboard' }] })
  }
})
