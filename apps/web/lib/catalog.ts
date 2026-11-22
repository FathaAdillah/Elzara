import type { components, operations } from "@elzara/api-client";

export type Product = components["schemas"]["ProductSummary"];
export type Variant = components["schemas"]["ProductVariant"];
export type ProductDetail =
  operations["getProduct"]["responses"][200]["content"]["application/json"];
export type Category = components["schemas"]["CategoryNode"];
export type CatalogQuery = NonNullable<
  operations["listProducts"]["parameters"]["query"]
>;
export type SearchParams = Record<string, string | string[] | undefined>;

const kinds = ["rts", "pre_order", "request_order"] as const;
const sorts = ["newest", "price_asc", "price_desc", "relevance"] as const;
const allowed = new Set([
  "q",
  "kind",
  "category",
  "brand",
  "collection",
  "price_min",
  "price_max",
  "in_stock",
  "sort",
  "limit",
  "cursor",
]);

export function rupiahToMinor(value: string): number | null {
  if (!/^\d+$/.test(value)) return null;
  const amount = Number(value);
  return Number.isSafeInteger(amount) && amount >= 0 ? amount : null;
}

export function minorToRupiah(value: number): string {
  if (!Number.isSafeInteger(value) || value < 0)
    throw new RangeError("Nilai rupiah tidak valid.");
  return String(value);
}

export function catalogHref(
  params: SearchParams,
  changes: Record<string, string | undefined> = {},
): string {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries({ ...params, ...changes })) {
    if (typeof value === "string" && value !== "") query.set(key, value);
  }
  return `/catalog${query.size ? `?${query}` : ""}`;
}

export function catalogQuery(
  params: SearchParams,
):
  | { query: CatalogQuery; error?: undefined }
  | { query?: undefined; error: string } {
  const invalid = {
    error:
      "Filter belum valid. Periksa kata pencarian, rentang harga, dan pilihan filter Anda.",
  };
  if (
    Object.entries(params).some(
      ([key, value]) => !allowed.has(key) || Array.isArray(value),
    )
  )
    return invalid;
  const value = (key: string) =>
    typeof params[key] === "string" ? (params[key] as string) : "";
  const query: CatalogQuery = {};
  const q = value("q").trim();
  if (q.length > 200) return invalid;
  if (q) query.q = q;
  const kind = kinds.find((kind) => kind === value("kind"));
  if (value("kind") && !kind) return invalid;
  if (kind) query.kind = kind;
  const sort = sorts.find((sort) => sort === value("sort"));
  if ((value("sort") && !sort) || (sort === "relevance" && !q)) return invalid;
  if (sort) query.sort = sort;
  for (const key of ["category", "brand", "collection"] as const) {
    if (value(key) && !/^[a-z0-9-]{1,80}$/.test(value(key))) return invalid;
    if (value(key)) query[key] = value(key);
  }
  for (const key of ["price_min", "price_max"] as const) {
    if (!value(key)) continue;
    const amount = rupiahToMinor(value(key));
    if (amount === null) return invalid;
    query[key] = amount;
  }
  if (
    query.price_min !== undefined &&
    query.price_max !== undefined &&
    query.price_max < query.price_min
  )
    return invalid;
  if (value("in_stock")) {
    if (!["true", "false", "1", "0"].includes(value("in_stock")))
      return invalid;
    query.in_stock = ["true", "1"].includes(value("in_stock"));
  }
  if (value("limit")) {
    const limit = rupiahToMinor(value("limit"));
    if (limit === null || limit < 1 || limit > 100) return invalid;
    query.limit = limit;
  }
  if (value("cursor")) {
    if (value("cursor").length > 8192) return invalid;
    query.cursor = value("cursor");
  }
  return { query };
}

export function resolveVariant(
  variants: Variant[],
  selected: Record<string, string>,
): Variant | undefined {
  return variants.find((variant) =>
    Object.entries(selected).every(
      ([name, value]) => variant.attributes?.[name] === value,
    ),
  );
}

export function hasCombination(
  variants: Variant[],
  selected: Record<string, string>,
  name: string,
  value: string,
): boolean {
  return resolveVariant(variants, { ...selected, [name]: value }) !== undefined;
}

export function availability(available: number, kind: Product["kind"]): string {
  if (kind === "pre_order") return "Pre-order";
  if (kind === "request_order") return "Minta penawaran";
  return available > 0 ? `Stok ${available}` : "Habis";
}

export function schemaAvailability(
  product: Pick<Product, "kind" | "available">,
): string {
  return `https://schema.org/${product.kind === "pre_order" ? "PreOrder" : product.available > 0 ? "InStock" : "OutOfStock"}`;
}
