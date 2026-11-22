export function adminRouteNavigation(path: string) {
  const normalized = path.replace(/\/$/, '') || '/'
  if (normalized === '/staff') {
    return { section: 'staff', breadcrumbs: [{ label: 'Akses staf' }] }
  }
  if (/^\/staff\/[^/]+\/access$/.test(normalized)) {
    return { section: 'staff', breadcrumbs: [{ label: 'Akses staf', to: '/staff' }, { label: 'Kelola akses' }] }
  }
  if (normalized === '/catalog') {
    return { section: 'catalog', breadcrumbs: [{ label: 'Katalog' }] }
  }
  if (normalized === '/settings/feature-flags') {
    return { section: 'feature-flags', breadcrumbs: [{ label: 'Pengaturan' }, { label: 'Feature flags' }] }
  }
  return { section: 'dashboard', breadcrumbs: [{ label: 'Dashboard' }] }
}
