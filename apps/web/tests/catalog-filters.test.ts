import { describe, expect, it } from "vitest";
import { FacetPanel } from "../components/shop/catalog-filters";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";

describe("filter form navigation identity", () => {
  it("renders translated category names instead of localization keys", () => {
    const markup = renderToStaticMarkup(createElement(FacetPanel, {
      categories: [{ slug: "model-kits", name_key: "catalog.models", name: "Model rakitan", parent: null, position: 0 }], params: {},
    }));
    expect(markup).toContain('value="model-kits">Model rakitan</option>');
    expect(markup).not.toContain("catalog.models");
  });
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

describe("active brand and collection filters", () => {
  it("preserves active filters as successful native GET controls", () => {
    const markup = renderToStaticMarkup(createElement(FacetPanel, {
      categories: [], params: { brand: "nova-workshop", collection: "summer", q: "novaa" },
    }));
    expect(markup).toContain('type="hidden" name="brand" value="nova-workshop"');
    expect(markup).toContain('type="hidden" name="collection" value="summer"');
  });

  it.each([{}, { brand: "", collection: "" }, { brand: ["one", "two"] }])(
    "omits missing, empty and repeated filters", (params) => {
      const markup = renderToStaticMarkup(createElement(FacetPanel, { categories: [], params }));
      expect(markup).not.toContain('type="hidden"');
    },
  );
});
