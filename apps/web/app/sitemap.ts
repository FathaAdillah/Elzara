import type { MetadataRoute } from "next";
import { getProducts } from "@/lib/catalog-api";
import { siteUrl } from "@/lib/seo";

export const dynamic = "force-dynamic";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const entries: MetadataRoute.Sitemap = [
    { url: siteUrl("/") },
    { url: siteUrl("/catalog") },
  ];
  const slugs = new Set<string>();
  const cursors = new Set<string>();
  let cursor: string | undefined;
  do {
    const result = await getProducts({ limit: 100, cursor });
    if (!result?.data) break;
    for (const product of result.data.data) {
      if (!slugs.has(product.slug)) {
        entries.push({ url: siteUrl(`/catalog/${product.slug}`) });
        slugs.add(product.slug);
      }
    }
    cursor = result.data.meta.next_cursor ?? undefined;
    if (cursor && cursors.has(cursor)) break;
    if (cursor) cursors.add(cursor);
  } while (cursor);
  return entries;
}
