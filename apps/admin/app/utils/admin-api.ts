import type { components, operations } from '@elzara/api-client'

export type StaffAccess = components['schemas']['AdminUserAccess']
export type PermissionGrant = components['schemas']['AdminPermissionGrant']
export type RoleAssignment = components['schemas']['AdminRoleAssignment']
export type AccessReason = components['schemas']['AdminAccessReason']
export type FeatureFlags = components['schemas']['FeatureFlagAdminList']
export type FeatureFlagsPatch = operations['adminFeatureFlagsPatch']['requestBody']['content']['application/json']
export type CatalogSearchResult = components['schemas']['CatalogSearchResult']
export type CatalogCsvImportResult = components['schemas']['CatalogCsvImportResult']
export type CatalogReindexBody = Record<string, never>

export function adminRead<T>(path: string) {
  return $fetch<T>(`/api/v1/admin${path}`, {
    credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', retry: 0,
  })
}

export async function adminMutate<T>(path: string, method: 'POST' | 'DELETE' | 'PATCH', body: PermissionGrant | RoleAssignment | AccessReason | FeatureFlagsPatch | CatalogReindexBody) {
  const token = await adminXsrfToken()
  return $fetch<T>(`/api/v1/admin${path}`, {
    method, body, credentials: 'same-origin', retry: 0,
    headers: {
      Accept: 'application/json',
      'X-XSRF-TOKEN': token,
      'Idempotency-Key': crypto.randomUUID(),
    },
  })
}

export async function adminDownload(path: string, filename: string) {
  const blob = await $fetch<Blob>(`/api/v1/admin${path}`, {
    credentials: 'same-origin', headers: { Accept: 'text/csv, application/problem+json' }, cache: 'no-store', retry: 0, responseType: 'blob',
  })
  const url = URL.createObjectURL(blob)
  const link = Object.assign(document.createElement('a'), { href: url, download: filename })
  link.click()
  URL.revokeObjectURL(url)
}

export async function adminUpload<T>(path: string, body: FormData) {
  const token = await adminXsrfToken()
  return $fetch<T>(`/api/v1/admin${path}`, {
    method: 'POST', body, credentials: 'same-origin', retry: 0,
    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
  })
}

async function adminXsrfToken() {
  await $fetch('/sanctum/csrf-cookie', {
    query: { scope: 'admin' }, credentials: 'same-origin', cache: 'no-store', retry: 0,
  })
  const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice(11)
  if (!token) throw createError({ statusCode: 419 })
  return decodeURIComponent(token)
}
