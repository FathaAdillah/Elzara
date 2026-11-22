import http from 'node:http'
import { spawn, execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const api = fileURLToPath(new URL('../../apps/api/', import.meta.url))
const apiPort = Number(process.env.E2E_API_PORT ?? 3089)
const env = {
  ...process.env,
  APP_ENV: 'local',
  APP_KEY: process.env.APP_KEY ?? 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
  APP_URL: 'http://localhost:3088',
  ELZARA_ADMIN_ORIGIN: 'http://admin.localhost:3088',
  ADMIN_2FA_ENABLED: 'false',
  SESSION_SECURE_COOKIE: 'false',
  SESSION_DOMAIN: '',
  SESSION_DRIVER: 'redis', CACHE_STORE: 'redis', QUEUE_CONNECTION: 'redis',
  DB_CONNECTION: 'pgsql', DB_HOST: process.env.DB_HOST ?? '127.0.0.1',
  DB_DATABASE: process.env.DB_DATABASE ?? 'elzara_test',
  DB_USERNAME: process.env.DB_USERNAME ?? 'elzara', DB_PASSWORD: process.env.DB_PASSWORD ?? 'elzara',
  REDIS_HOST: process.env.REDIS_HOST ?? '127.0.0.1',
  MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr',
}
execFileSync('php', ['artisan', 'config:clear'], { cwd: api, env, stdio: 'inherit' })
execFileSync('php', ['artisan', 'migrate', '--force'], { cwd: api, env, stdio: 'inherit' })
execFileSync('php', ['fixture.php'], { cwd: fileURLToPath(new URL('.', import.meta.url)), env, stdio: 'inherit' })
const backend = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${apiPort}`, '--no-reload'], { cwd: api, env, stdio: 'inherit' })
const server = http.createServer((req, res) => {
  if (req.url === '/ready') {
    const probe = http.get(`http://127.0.0.1:${apiPort}/up`, upstream => { res.writeHead(upstream.statusCode); upstream.pipe(res) })
    probe.on('error', () => { res.writeHead(503); res.end() })
    return
  }
  if (req.url === '/' || req.url === '/echo') {
    res.setHeader('Content-Type', 'text/html')
    res.end('<!doctype html><title>Host isolation harness</title><h1>Host isolation harness</h1>')
    return
  }
  if (req.url === '/cookie-echo') {
    res.setHeader('Content-Type', 'application/json')
    res.end(JSON.stringify({ cookie: req.headers.cookie ?? '' }))
    return
  }
  const upstream = http.request({ hostname: '127.0.0.1', port: apiPort, path: req.url, method: req.method, headers: req.headers }, response => {
    res.writeHead(response.statusCode, response.headers)
    response.pipe(res)
  })
  upstream.on('error', () => { res.writeHead(502); res.end() })
  req.pipe(upstream)
})
server.listen(3088, '127.0.0.1')
function cleanup() { server.close(); backend.kill('SIGTERM'); process.exit() }
process.on('SIGTERM', cleanup)
process.on('SIGINT', cleanup)
backend.on('exit', code => { if (code) process.exit(code) })
