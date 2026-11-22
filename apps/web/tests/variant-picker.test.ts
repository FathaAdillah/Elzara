import { beforeEach, describe, expect, it, vi } from "vitest";
import { createElement, isValidElement, useState, type ReactNode } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { VariantPicker } from "../components/shop/variant-picker";
import type { ProductDetail } from "../lib/catalog";

vi.mock("react", async (importOriginal) => ({
  ...(await importOriginal<typeof import("react")>()),
  useState: vi.fn(),
}));

const product: ProductDetail = {
  id: "p",
  slug: "figure",
  name: "Figure",
  kind: "rts",
  available: 5,
  price: { amount_minor: 100000, currency: "IDR" },
  description_html: "",
  media: [],
  variants: [
    {
      id: "first",
      sku: "FIG-A",
      label: "Edition A",
      available: 5,
      price: { amount_minor: 100000, currency: "IDR" },
      attributes: {},
    },
    {
      id: "second",
      sku: "FIG-B",
      available: 0,
      price: { amount_minor: 150000, currency: "IDR" },
      attributes: {},
    },
  ],
};

function radioChange(node: ReactNode, value: string): (() => void) | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const change = radioChange(child, value);
      if (change) return change;
    }
  } else if (isValidElement<Record<string, unknown>>(node)) {
    if (node.type === "input" && node.props.value === value)
      return node.props.onChange as () => void;
    return radioChange(node.props.children as ReactNode, value);
  }
}

describe("variants without option axes", () => {
  beforeEach(() => {
    let selectedId: string | undefined;
    vi.mocked(useState).mockImplementation(((initial: unknown) => [
      typeof initial === "string" ? (selectedId ?? initial) : initial,
      (id: string) => {
        selectedId = id;
      },
    ]) as typeof useState);
  });

  it("offers each SKU and updates the selected price, SKU and stock", () => {
    let tree = VariantPicker({ product });
    let markup = renderToStaticMarkup(tree);
    expect(markup).toContain("Edition A");
    expect(markup).toContain("FIG-B · Habis");
    expect(markup).toContain('name="variant"');
    expect(markup).toContain("SKU: FIG-A");
    const selectSecond = radioChange(tree, "second");
    expect(selectSecond).toBeTypeOf("function");
    selectSecond!();
    tree = VariantPicker({ product });
    markup = renderToStaticMarkup(tree);
    expect(markup).toContain("SKU: FIG-B");
    expect(markup).toContain("150.000");
    expect(markup).toContain("Varian ini sedang habis.");
    expect(markup).toContain('aria-live="polite"');
  });

  it("keeps single-variant behavior without an extra control", () => {
    const markup = renderToStaticMarkup(
      createElement(VariantPicker, {
        product: { ...product, variants: product.variants.slice(0, 1) },
      }),
    );
    expect(markup).toContain("SKU: FIG-A");
    expect(markup).not.toContain('name="variant"');
  });

  it("handles empty variants without selecting a nonexistent SKU", () => {
    const markup = renderToStaticMarkup(
      createElement(VariantPicker, { product: { ...product, variants: [] } }),
    );
    expect(markup).toContain("SKU: —");
    expect(markup).not.toContain('name="variant"');
  });
});
