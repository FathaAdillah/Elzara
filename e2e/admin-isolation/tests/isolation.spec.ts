import { test, expect, type Page } from '@playwright/test'

const web = 'http://localhost:3088'
const admin = 'http://admin.localhost:3088'
const password = 'isolation-password'

async function mutate(page: Page, path: string, body: object, scope = '') {
  return page.evaluate(async ({ path, body, scope }) => {
    await fetch('/sanctum/csrf-cookie' + scope)
    const token = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))!.slice(11)
    const response = await fetch(path, {
      method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token) },
      body: JSON.stringify(body),
    })
    return { status: response.status, body: await response.text() }
  }, { path, body, scope })
}

test('browser cookies and authenticated sessions remain isolated across localhost and admin.localhost', async ({ context }) => {
  const storefront = await context.newPage()
  await storefront.goto(web)
  expect((await mutate(storefront, '/api/v1/auth/register', { email: 'isolation-customer@example.test', name: 'Isolation customer', password })).status).toBe(201)
  expect((await mutate(storefront, '/api/v1/auth/logout', {})).status).toBe(204)
  expect((await mutate(storefront, '/api/v1/auth/login', { email: 'isolation-customer@example.test', password })).status).toBe(200)

  const operator = await context.newPage()
  await operator.goto(admin)
  // Before admin bootstrap, the web cookie must not be sent to this host.
  expect(await operator.evaluate(() => fetch('/cookie-echo').then(r => r.json()))).toEqual({ cookie: '' })
  const bootstrap = await operator.evaluate(async () => {
    const r = await fetch('/sanctum/csrf-cookie?scope=admin&purpose=login')
    return r.status
  })
  expect(bootstrap).toBe(204)
  const challenge = (await context.cookies(admin)).find(c => c.name === 'elzara_admin_challenge')!
  expect(challenge.domain).toBe('admin.localhost')
  expect(challenge.httpOnly).toBe(true)
  expect((await mutate(operator, '/api/v1/admin/auth/login', { email: 'isolation-operator@example.test', password }, '?scope=admin&purpose=login')).status).toBe(200)
  expect(await operator.evaluate(() => fetch('/api/v1/admin/auth/session').then(r => r.status))).toBe(200)
  expect(await storefront.evaluate(() => fetch('/api/v1/me/sessions').then(r => r.status))).toBe(200)

  const cookies = await context.cookies()
  for (const [host, session] of [['localhost', 'elzara_web_session'], ['admin.localhost', 'elzara_admin_session']] as const) {
    for (const name of [session, 'XSRF-TOKEN']) {
      const cookie = cookies.find(c => c.name === name && c.domain === host)!
      expect(cookie).toBeDefined()
      expect(cookie.domain).not.toMatch(/^\./)
      expect(cookie.path).toBe('/')
      expect(cookie.sameSite).toBe('Lax')
      expect(cookie.httpOnly).toBe(name !== 'XSRF-TOKEN')
    }
  }
  const webCookies = (await storefront.evaluate(() => fetch('/cookie-echo').then(r => r.json()))).cookie
  expect(webCookies).toContain('elzara_web_session=')
  expect(webCookies).not.toContain('elzara_admin_')
  const adminCookies = (await operator.evaluate(() => fetch('/cookie-echo').then(r => r.json()))).cookie
  expect(adminCookies).toContain('elzara_admin_session=')
  expect(adminCookies).not.toContain('elzara_web_session=')
  expect(await operator.evaluate(() => fetch('/api/v1/me/sessions').then(r => r.status))).toBe(403)
  expect(await operator.evaluate(() => fetch('/sanctum/csrf-cookie').then(r => r.status))).toBe(403)
  expect(await storefront.evaluate(() => fetch('/api/v1/admin/auth/session').then(r => r.status))).toBe(403)
  expect(await storefront.evaluate(() => fetch('/sanctum/csrf-cookie?scope=admin').then(r => r.status))).toBe(403)

  // Chromium must deny reading the admin session from the storefront origin.
  expect(await storefront.evaluate(async (admin) => {
    try { await fetch(admin + '/api/v1/admin/auth/session', { credentials: 'include' }); return 'allowed' }
    catch (error) { return (error as Error).name }
  }, admin)).toBe('TypeError')
  const denied = await context.request.get(admin + '/api/v1/admin/auth/session', { headers: { Origin: web } })
  expect(denied.status()).toBe(403)
  expect(denied.headers()['access-control-allow-origin']).toBeUndefined()

  // SSR uses the internal transport URL and explicitly supplies the public admin Host.
  const session = cookies.find(c => c.name === 'elzara_admin_session')!
  const ssr = await context.request.get(`http://127.0.0.1:${process.env.E2E_API_PORT ?? 3089}/api/v1/admin/auth/session`, {
    headers: { Host: 'admin.localhost:3088', Origin: admin, Cookie: `${session.name}=${session.value}` },
  })
  expect(ssr.status()).toBe(200)
  const forged = await context.request.get(web + '/api/v1/admin/auth/session', {
    headers: { Origin: admin, 'X-Forwarded-Host': 'admin.localhost:3088' },
  })
  expect(forged.status()).toBe(403)

  expect((await mutate(operator, '/api/v1/admin/auth/logout', {}, '?scope=admin')).status).toBe(204)
  expect(await operator.evaluate(() => fetch('/api/v1/admin/auth/session').then(r => r.status))).toBe(401)
  expect(await storefront.evaluate(() => fetch('/api/v1/me/sessions').then(r => r.status))).toBe(200)
})
