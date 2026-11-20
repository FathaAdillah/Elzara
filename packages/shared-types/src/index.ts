// Uang TIDAK didefinisikan di sini (integer minor unit + currency adalah milik openapi.yaml, Doc 01 §6.1 / Doc 12 G5),
// dan enum status TIDAK didefinisikan di sini juga — satu-satunya daftar ada di app/Platform/Domain/Status yang
// dibangun dari CHECK Doc 09 (Doc 11 §8.3, D-S0-4). Menyalin salah satunya ke paket ini adalah temuan review.

/**
 * Bentuk pagination kanonik (Doc 12 §3.3): cursor, bukan offset.
 * `total`/`last_page` sengaja tidak ada - COUNT(*) tidak dihitung di jalur daftar.
 */
export interface CursorPage<T> {
  readonly data: readonly T[];
  readonly meta: CursorMeta;
}

export interface CursorMeta {
  readonly limit: number;
  readonly has_more: boolean;
  readonly next_cursor: string | null;
  readonly prev_cursor: string | null;
}

/**
 * Waktu di kawat (Doc 12 §3.6): selalu UTC ISO 8601 dengan `Z`, presisi detik atau milidetik.
 * Offset lokal (`+07:00`), naive datetime, dan epoch integer tidak pernah lewat.
 */
export type InstantIso = string & { readonly __brand: "InstantIso" };

/** Tanggal murni tanpa jam (ETA batch, tanggal lahir): `format: date` di spec, bukan timestamp. */
export type DateOnly = string & { readonly __brand: "DateOnly" };

/** Durasi ISO 8601, mis. TTL reservasi `PT2H` (Doc 12 §3.6). */
export type IsoDuration = string & { readonly __brand: "IsoDuration" };

/** Timezone tampilan mengikuti akun, bukan browser; dikirim balik sebagai `X-Displayed-Timezone` (Doc 12 §3.6). */
export const DEFAULT_DISPLAY_TIMEZONE = "Asia/Jakarta";

/**
 * ID di permukaan API adalah ULID berprefiks, tidak pernah PK integer (Doc 12 §3.6, S-4).
 * Prefiks di bawah adalah daftar yang sama; pattern regex per operasi dimiliki openapi.yaml.
 */
export type PrefixedId<TPrefix extends string> = `${TPrefix}_${string}` & { readonly __brand: "PrefixedId" };

export type OrderId = PrefixedId<"ord">;
export type OrderItemId = PrefixedId<"item">;
export type CheckoutSessionId = PrefixedId<"cs">;
export type InvoiceId = PrefixedId<"inv">;
export type PaymentIntentId = PrefixedId<"pay">;
export type StockReservationId = PrefixedId<"rsv">;
export type QuotationId = PrefixedId<"quo">;
export type MediaId = PrefixedId<"media">;
export type EventId = PrefixedId<"evt">;

/** `X-Request-Id`: dipakai apa adanya bila klien mengirim, dibuat ULID bila kosong (Doc 12 §3.2). */
export type RequestId = string;

export const REQUEST_ID_PATTERN = /^[A-Za-z0-9._-]{1,128}$/;
