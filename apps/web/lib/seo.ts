import { brand } from "@/config/brand";
import { schemaAvailability, type ProductDetail } from "./catalog";

export function siteUrl(path = "/"): string {
  return new URL(
    path,
    process.env.NEXT_PUBLIC_SITE_URL || "http://localhost:3000",
  ).toString();
}

export function productJsonLd(product: ProductDetail) {
  return {
    "@context": "https://schema.org",
    "@type": "Product",
    name: product.name,
    description: product.meta_description || product.name,
    image: product.primary_media?.url || brand.seo.defaultOg || undefined,
    brand: product.brand
      ? { "@type": "Brand", name: product.brand }
      : undefined,
    offers: {
      "@type": "Offer",
      url: siteUrl(`/catalog/${product.slug}`),
      priceCurrency: "IDR",
      price: product.price.amount_minor,
      availability: schemaAvailability(product),
    },
  };
}

export function serializeJsonLd(value: unknown): string {
  return JSON.stringify(value).replace(/</g, "\\u003c");
}
