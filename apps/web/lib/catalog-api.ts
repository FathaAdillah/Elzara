import { cache } from "react";
import { api } from "./api";
import type { CatalogQuery } from "./catalog";

export async function getProducts(query: CatalogQuery = {}) {
  try {
    return await api.GET("/products", { params: { query }, cache: "no-store" });
  } catch {
    return undefined;
  }
}

export const getCategories = cache(async () => {
  try {
    const result = await api.GET("/categories", { cache: "no-store" });
    return result.data?.data ?? [];
  } catch {
    return [];
  }
});

export const getProduct = cache(async (slug: string) => {
  try {
    return await api.GET("/products/{slug}", {
      params: { path: { slug } },
      cache: "no-store",
    });
  } catch {
    return undefined;
  }
});
