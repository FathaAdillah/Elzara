import { afterEach, describe, expect, it, vi } from "vitest";
import type { ProductDetail } from "../lib/catalog";
import { productJsonLd, serializeJsonLd } from "../lib/seo";

const { getProducts } = vi.hoisted(() => ({ getProducts: vi.fn() }));
vi.mock("@/lib/catalog-api", () => ({ getProducts }));
import sitemap from "../app/sitemap";

afterEach(() => {
  vi.unstubAllEnvs();
  getProducts.mockReset();
});

describe("SEO", () => {
  it("serializes JSON-LD without allowing script breakouts", () => {
    const json = serializeJsonLd({
      name: "</script><script>alert(1)</script>",
    });
    expect(json).not.toContain("<");
    expect(JSON.parse(json).name).toBe("</script><script>alert(1)</script>");
  });
  it("uses effective wire prices and mode-aware stock", () => {
    const product: ProductDetail = {
      id: "p",
      slug: "figure",
      name: "Figure",
      price: { amount_minor: 100000, currency: "IDR" },
      available: 0,
      kind: "pre_order",
      description_html: "",
      variants: [],
      media: [],
    };
    expect(productJsonLd(product)).toMatchObject({
      "@type": "Product",
      offers: {
        price: 100000,
        priceCurrency: "IDR",
        availability: "https://schema.org/PreOrder",
      },
    });
  });
  it("pages every product, deduplicating slugs", async () => {
    vi.stubEnv("NEXT_PUBLIC_SITE_URL", "https://shop.example");
    getProducts
      .mockResolvedValueOnce({
        data: { data: [{ slug: "one" }], meta: { next_cursor: "next" } },
      })
      .mockResolvedValueOnce({
        data: {
          data: [{ slug: "one" }, { slug: "two" }],
          meta: { next_cursor: null },
        },
      });
    expect((await sitemap()).map((entry) => entry.url)).toEqual([
      "https://shop.example/",
      "https://shop.example/catalog",
      "https://shop.example/catalog/one",
      "https://shop.example/catalog/two",
    ]);
    expect(getProducts).toHaveBeenLastCalledWith({
      limit: 100,
      cursor: "next",
    });
  });
  it("tolerates API downtime and empty catalogs", async () => {
    getProducts.mockResolvedValueOnce(undefined);
    expect(await sitemap()).toHaveLength(2);
    getProducts.mockResolvedValueOnce({
      data: { data: [], meta: { next_cursor: null } },
    });
    expect(await sitemap()).toHaveLength(2);
  });
  it("does not loop forever on a repeated cursor", async () => {
    getProducts.mockResolvedValue({
      data: { data: [{ slug: "one" }], meta: { next_cursor: "same" } },
    });
    expect(await sitemap()).toHaveLength(3);
    expect(getProducts).toHaveBeenCalledTimes(2);
  });
});
