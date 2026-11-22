import { describe, expect, it } from "vitest";
import { FacetPanel } from "../components/shop/catalog-filters";

describe("filter form navigation identity", () => {
  it("remounts uncontrolled fields when navigation clears the query", () => {
    const filtered = FacetPanel({
      categories: [],
      params: { kind: "rts", sort: "price_desc" },
    });
    const cleared = FacetPanel({ categories: [], params: {} });

    expect(filtered.key).not.toBe(cleared.key);
    expect(cleared.key).toBe("/catalog");
  });

  it.each(["q", "kind", "category", "price_min", "price_max", "in_stock", "sort"])(
    "remounts when the %s query value changes",
    (name) => {
      const before = FacetPanel({ categories: [], params: { [name]: "first" } });
      const after = FacetPanel({ categories: [], params: { [name]: "second" } });

      expect(before.key).not.toBe(after.key);
    },
  );

  it("keeps the form identity for an unchanged query", () => {
    const props = { categories: [], params: { q: "novaa", kind: "pre_order" } };

    expect(FacetPanel(props).key).toBe(FacetPanel(props).key);
  });
});
