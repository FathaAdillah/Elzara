import type { components, operations } from '@elzara/api-client'

export type StaffAccess = components['schemas']['AdminUserAccess']
export type PermissionGrant = components['schemas']['AdminPermissionGrant']
export type RoleAssignment = components['schemas']['AdminRoleAssignment']
export type AccessReason = components['schemas']['AdminAccessReason']
export type FeatureFlags = components['schemas']['FeatureFlagAdminList']
export type FeatureFlagsPatch = operations['adminFeatureFlagsPatch']['requestBody']['content']['application/json']

export function adminRead<T>(path: string) {
  return $fetch<T>(`/api/v1/admin${path}`, {
    credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', retry: 0,
  })
}

export async function adminMutate<T>(path: string, method: 'POST' | 'DELETE' | 'PATCH', body: PermissionGrant | RoleAssignment | AccessReason | FeatureFlagsPatch) {
  await $fetch('/sanctum/csrf-cookie', {
    query: { scope: 'admin' }, credentials: 'same-origin', cache: 'no-store', retry: 0,
  })
  const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice(11)
  if (!token) throw createError({ statusCode: 419 })
  return $fetch<T>(`/api/v1/admin${path}`, {
    method, body, credentials: 'same-origin', retry: 0,
    headers: {
      Accept: 'application/json',
      'X-XSRF-TOKEN': decodeURIComponent(token),
      'Idempotency-Key': crypto.randomUUID(),
    },
  })
}
