import { randomBytes } from 'node:crypto';
import { mkdtempSync, writeFileSync } from 'node:fs';

const cache = mkdtempSync('/workspace/e2e/laravel-cache-');

const environment = {
  APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: 'http://127.0.0.1:3080',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_MAINTENANCE_DRIVER: 'file',
  APP_CONFIG_CACHE: `${cache}/config.php`, APP_ROUTES_CACHE: `${cache}/routes.php`,
  APP_EVENTS_CACHE: `${cache}/events.php`, APP_SERVICES_CACHE: `${cache}/services.php`,
  APP_PACKAGES_CACHE: `${cache}/packages.php`,
  DB_CONNECTION: 'pgsql', DB_HOST: 'postgres', DB_PORT: '5432', DB_DATABASE: 'elzara_e2e',
  DB_USERNAME: 'elzara', DB_PASSWORD: 'elzara',
  REDIS_HOST: 'redis', REDIS_PORT: '6379', REDIS_PREFIX: 'e2e_', CACHE_STORE: 'redis',
  SESSION_DRIVER: 'redis', SESSION_SECURE_COOKIE: 'false', QUEUE_CONNECTION: 'sync',
  MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr', SHIPPING_DRIVER: 'manual', ADMIN_2FA_ENABLED: 'false',
  DEMO_PASSWORD: process.env.DEMO_PASSWORD,
  ELZARA_STOREFRONT_PROXY_KEY: randomBytes(32).toString('hex'),
  API_INTERNAL_URL: 'http://api:8081', NEXT_PUBLIC_API_BASE_URL: '/api/v1',
  E2E_BASE_URL: 'http://127.0.0.1:3080', NEXT_TELEMETRY_DISABLED: '1',
  E2E_PROXY_API_HOST: 'api', E2E_PROXY_WEB_HOST: 'web', E2E_PROXY_BIND: '0.0.0.0',
};
writeFileSync('e2e/runtime.sh', Object.entries(environment)
  .map(([key, value]) => `export ${key}='${String(value).replaceAll("'", "'\\''")}'`).join('\n'), { mode: 0o600 });
writeFileSync('apps/api/.env', '');
