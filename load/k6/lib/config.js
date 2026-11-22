// Konfigurasi bersama: semua nilai dari env (k6 -e / --env), jadi skrip yang sama berjalan
// ke stack Docker lokal maupun staging. Tidak ada host atau kredensial yang di-hardcode.
export const BASE_URL = (__ENV.BASE_URL || 'http://localhost').replace(/\/+$/, '');
export const API = `${BASE_URL}/api/v1`;
export const PROFILE = __ENV.PROFILE || 'smoke';
export const PASSWORD = __ENV.LOADTEST_PASSWORD || '';
export const CUSTOMERS = int('LOADTEST_CUSTOMERS', 50);
export const THINK_SECONDS = float('THINK_SECONDS', PROFILE === 'smoke' ? 0.5 : 1);

export function int(name, fallback) {
  const raw = __ENV[name];
  return raw === undefined || raw === '' ? fallback : parseInt(raw, 10);
}

export function float(name, fallback) {
  const raw = __ENV[name];
  return raw === undefined || raw === '' ? fallback : parseFloat(raw);
}

export function requirePassword() {
  if (PASSWORD.length < 12) {
    throw new Error('LOADTEST_PASSWORD wajib di-set (sama dengan yang dipakai LoadTestSeeder).');
  }
}

export function customerEmail(index) {
  return `load.customer.${String(index).padStart(4, '0')}@elzara.test`;
}

// Profil smoke membuktikan skrip berjalan; baseline adalah bentuk beban untuk ukur kapasitas staging.
// Override per run lewat VUS / DURATION / RATE / ITERATIONS bila perlu.
export function catalogScenario() {
  if (PROFILE === 'baseline') {
    return {
      executor: 'ramping-arrival-rate',
      startRate: 5,
      timeUnit: '1s',
      preAllocatedVUs: int('VUS', 50),
      maxVUs: int('MAX_VUS', 300),
      stages: [
        { target: int('RATE', 50), duration: '2m' },
        { target: int('RATE', 50), duration: __ENV.DURATION || '5m' },
        { target: 0, duration: '30s' },
      ],
    };
  }
  return { executor: 'constant-vus', vus: int('VUS', 3), duration: __ENV.DURATION || '30s' };
}

export function checkoutScenario() {
  if (PROFILE === 'baseline') {
    return {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { target: int('VUS', 20), duration: '2m' },
        { target: int('VUS', 20), duration: __ENV.DURATION || '5m' },
        { target: 0, duration: '30s' },
      ],
    };
  }
  return { executor: 'per-vu-iterations', vus: int('VUS', 2), iterations: int('ITERATIONS', 3), maxDuration: '2m' };
}
