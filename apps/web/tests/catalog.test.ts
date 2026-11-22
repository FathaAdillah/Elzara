import { describe, expect, it } from "vitest";
import {
  availability,
  catalogHref,
  catalogQuery,
  hasCombination,
  minorToRupiah,
  resolveVariant,
  rupiahToMinor,
  schemaAvailability,
  type Variant,
} from "../lib/catalog";
import { formatIdr } from "../lib/money";

const variants: Variant[] = [
  {
    id: "one",
    sku: "MINT-S",
    label: "Mint S",
    price: { amount_minor: 149000, currency: "IDR" },
    available: 5,
    attributes: { Warna: "Mint", Ukuran: "S" },
  },
  {
    id: "two",
    sku: "MINT-M",
    label: "Mint M",
    price: { amount_minor: 159000, currency: "IDR" },
    available: 0,
    attributes: { Warna: "Mint", Ukuran: "M" },
  },
  {
    id: "three",
    sku: "LAVENDER-S",
    price: { amount_minor: 149000, currency: "IDR" },
    available: 2,
    attributes: { Warna: "Lavender", Ukuran: "S" },
  },
];

describe("rupiah wire amounts", () => {
  it("uses whole rupiah as the API minor unit, without a factor of 100", () => {
    expect(rupiahToMinor("149000")).toBe(149000);
    expect(rupiahToMinor("0")).toBe(0);
    expect(minorToRupiah(149000)).toBe("149000");
    expect(formatIdr(149000).replace(/\s/g, "")).toBe("Rp149.000");
  });
  it.each([
    "",
    " ",
    "1.000",
    "1,000",
    "1.5",
    "-1",
    "Infinity",
    "1e5",
    "9007199254740992",
  ])("rejects invalid input %s", (value) => {
    expect(rupiahToMinor(value)).toBeNull();
  });
  it("rejects invalid minor-unit values", () => {
    expect(() => minorToRupiah(-1)).toThrow(RangeError);
    expect(() => minorToRupiah(1.5)).toThrow(RangeError);
  });
});

describe("catalog filters and links", () => {
  it("omits empty GET fields, preserving zero prices and false stock", () => {
    expect(
      catalogQuery({
        q: "  gundum ",
        kind: "pre_order",
        price_min: "0",
        price_max: "200000",
        in_stock: "false",
        sort: "relevance",
        category: "model-kits",
        cursor: "signed",
        limit: "8",
        brand: "bandai",
        collection: "unicorn",
      }),
    ).toEqual({
      query: {
        q: "gundum",
        kind: "pre_order",
        price_min: 0,
        price_max: 200000,
        in_stock: false,
        sort: "relevance",
        category: "model-kits",
        cursor: "signed",
        limit: 8,
        brand: "bandai",
        collection: "unicorn",
      },
    });
    expect(
      catalogQuery({ q: "", kind: "", price_min: "", in_stock: "" }),
    ).toEqual({ query: {} });
    expect(catalogQuery({ in_stock: "1" })).toEqual({
      query: { in_stock: true },
    });
  });
  it.each([
    { unknown: "x" },
    { q: ["one", "two"] },
    { q: "a".repeat(201) },
    { kind: "po" },
    { sort: "invalid" },
    { sort: "relevance" },
    { price_min: "20", price_max: "10" },
    { price_min: "-1" },
    { price_max: "1.2" },
    { in_stock: "yes" },
    { limit: "0" },
    { limit: "101" },
    { category: "bad slug" },
    { cursor: "a".repeat(8193) },
  ])("returns friendly errors for invalid filters", (params) => {
    expect(catalogQuery(params)).toHaveProperty("error");
  });
  it("preserves filters and encodes cursor and search characters", () => {
    const href = catalogHref(
      {
        q: "Mint & fox",
        kind: "pre_order",
        price_min: "100000",
        cursor: "old",
        category: "",
      },
      { cursor: "new+/=" },
    );
    expect(href).toBe(
      "/catalog?q=Mint+%26+fox&kind=pre_order&price_min=100000&cursor=new%2B%2F%3D",
    );
    expect(catalogHref({}, { cursor: undefined })).toBe("/catalog");
  });
});

describe("multi-axis variants", () => {
  it("resolves the selected SKU, live price and availability", () => {
    expect(
      resolveVariant(variants, { Warna: "Mint", Ukuran: "M" }),
    ).toMatchObject({
      sku: "MINT-M",
      available: 0,
      price: { amount_minor: 159000 },
    });
    expect(
      resolveVariant(variants, { Warna: "Lavender", Ukuran: "S" })?.sku,
    ).toBe("LAVENDER-S");
  });
  it("distinguishes nonexistent combinations from out-of-stock variants", () => {
    expect(
      hasCombination(variants, { Warna: "Mint", Ukuran: "S" }, "Ukuran", "M"),
    ).toBe(true);
    expect(
      hasCombination(
        variants,
        { Warna: "Lavender", Ukuran: "S" },
        "Ukuran",
        "M",
      ),
    ).toBe(false);
    expect(
      resolveVariant(variants, { Warna: "Lavender", Ukuran: "M" }),
    ).toBeUndefined();
  });
  it("handles empty variants and resets, including products without axes", () => {
    expect(resolveVariant([], {})).toBeUndefined();
    expect(hasCombination([], {}, "Warna", "Mint")).toBe(false);
    expect(hasCombination(variants, {}, "Ukuran", "M")).toBe(true);
    expect(resolveVariant([{ ...variants[0]!, attributes: {} }], {})?.sku).toBe(
      "MINT-S",
    );
  });
  it("formats mode-aware availability and structured data values", () => {
    expect(availability(3, "rts")).toBe("Stok 3");
    expect(availability(0, "rts")).toBe("Habis");
    expect(availability(0, "pre_order")).toBe("Pre-order");
    expect(availability(0, "request_order")).toBe("Minta penawaran");
    expect(schemaAvailability({ kind: "rts", available: 1 })).toContain(
      "/InStock",
    );
    expect(schemaAvailability({ kind: "rts", available: 0 })).toContain(
      "/OutOfStock",
    );
    expect(schemaAvailability({ kind: "pre_order", available: 0 })).toContain(
      "/PreOrder",
    );
  });
});
