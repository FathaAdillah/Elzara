import { brand, resolveBrandTheme } from '@elzara/ui/brand'

export default defineNuxtConfig({
  compatibilityDate: '2026-11-19',
  modules: ['@nuxt/ui'],
  css: ['~/assets/css/main.css'],
  devtools: { enabled: false },
  ui: { fonts: false },
  colorMode: { preference: 'light', storageKey: 'elzara-admin-theme' },
  icon: {
    mode: 'svg',
    provider: 'none',
    fallbackToApi: false,
    serverBundle: false,
    clientBundle: { scan: { globInclude: ['**/*.{vue,js,ts}'] } },
  },
  runtimeConfig: {
    apiInternalOrigin: 'http://nginx:8080',
    adminOrigin: 'http://admin.localhost',
    adminSessionPath: '/api/v1/admin/auth/session',
    public: { previewEnabled: false },
  },
  app: {
    head: {
      htmlAttrs: { lang: brand.locale, 'data-brand-theme': resolveBrandTheme(process.env.BRAND_THEME ?? process.env.NUXT_PUBLIC_BRAND_THEME, 'admin') },
      titleTemplate: `%s · ${brand.name} Admin`,
      link: [{ rel: 'icon', type: 'image/png', href: '/brand/elzara-mark.png' }],
    },
  },
  routeRules: {
    '/': { headers: { 'cache-control': 'no-store' } },
    '/overview': { headers: { 'cache-control': 'no-store' } },
    '/preview': { headers: { 'cache-control': 'no-store' } },
  },
})
