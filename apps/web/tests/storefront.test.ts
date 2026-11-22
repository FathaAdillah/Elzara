import { afterEach, describe, expect, it, vi } from "vitest";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { CategoryTiles } from "../components/shop/category-navigation";
import { SiteNavigation } from "../components/shop/site-navigation";
import { storefront } from "../config/storefront";
import type { Category } from "../lib/catalog";

vi.mock("next/navigation", () => ({ usePathname: () => "/" }));

const category: Category = {
  slug: "constructor",
  name: "Constructor",
  name_key: "constructor",
  parent: null,
  position: 0,
};
const categoryImages = storefront.categoryImages;
afterEach(() => {
  storefront.categoryImages = categoryImages;
});

describe("storefront category artwork", () => {
  it("renders a valid constructor category without treating its prototype as an image", () => {
    const markup = renderToStaticMarkup(
      createElement(CategoryTiles, { categories: [category] }),
    );
    expect(markup).toContain('href="/catalog?category=constructor"');
    expect(markup).not.toContain("<img");
  });

  it("uses explicitly configured artwork even for a constructor category", () => {
    storefront.categoryImages = { constructor: "/brand/banner-placeholder.svg" };
    const markup = renderToStaticMarkup(
      createElement(CategoryTiles, { categories: [category] }),
    );
    expect(markup).toContain('src="/brand/banner-placeholder.svg"');
  });
});

it("gives the header cart link an accessible name independent of its hidden mobile text", () => {
  const markup = renderToStaticMarkup(createElement(SiteNavigation));
  expect(markup).toMatch(/<a[^>]*aria-label="Keranjang"[^>]*href="\/cart"/);
});
