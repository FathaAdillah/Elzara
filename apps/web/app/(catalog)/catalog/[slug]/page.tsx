import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { headers } from "next/headers";
import Link from "next/link";
import { brand } from "@/config/brand";
import { getProduct } from "@/lib/catalog-api";
import { availability } from "@/lib/catalog";
import { formatIdr } from "@/lib/money";
import { productJsonLd, serializeJsonLd } from "@/lib/seo";
import { CatalogMessage, ModeBadge } from "@/components/shop/products";
import { Gallery } from "@/components/shop/gallery";
import { VariantPicker } from "@/components/shop/variant-picker";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const result = await getProduct(slug);
  if (result?.response.status === 404) notFound();
  const product = result?.data;
  if (!product)
    return { title: "Produk sedang tidak tersedia", robots: { index: false } };
  const title = product.meta_title || product.name;
  const description =
    product.meta_description ||
    `${product.name}${product.brand ? ` · ${product.brand}` : ""}. ${availability(product.available, product.kind)}.`;
  const image = product.primary_media?.url || brand.seo.defaultOg;
  return {
    title,
    description,
    alternates: { canonical: `/catalog/${product.slug}` },
    openGraph: {
      title,
      description,
      url: `/catalog/${product.slug}`,
      images: image ? [image] : [],
    },
  };
}

export default async function ProductPage({ params }: Props) {
  const { slug } = await params;
  const result = await getProduct(slug);
  if (result?.response.status === 404) notFound();
  const product = result?.data;
  if (!product)
    return (
      <CatalogMessage>
        Produk sedang tidak tersedia. Silakan coba kembali atau{" "}
        <Link href="/catalog" className="underline">
          buka katalog
        </Link>
        .
      </CatalogMessage>
    );
  const nonce = (await headers()).get("x-nonce") ?? undefined;
  return (
    <div className="space-y-8">
      <nav
        aria-label="Breadcrumb"
        className="flex flex-wrap items-center gap-3 text-sm text-ink-muted"
      >
        <Link className="inline-flex min-h-touch items-center" href="/">
          Beranda
        </Link>
        <span aria-hidden="true">/</span>
        <Link className="inline-flex min-h-touch items-center" href="/catalog">
          Katalog
        </Link>
        {product.category && (
          <>
            <span aria-hidden="true">/</span>
            <Link
              className="inline-flex min-h-touch items-center"
              href={`/catalog?category=${encodeURIComponent(product.category.slug)}`}
            >
              {product.category.name}
            </Link>
          </>
        )}
        <span aria-hidden="true">/</span>
        <span aria-current="page">{product.name}</span>
      </nav>
      <div className="grid items-start gap-8 lg:grid-cols-2 lg:gap-12">
        <Gallery product={product} />
        <div className="space-y-6">
          <ModeBadge kind={product.kind} />
          <div className="space-y-3">
            {product.brand && (
              <p className="text-sm text-ink-muted">{product.brand}</p>
            )}
            <h1 className="text-4xl font-semibold tracking-tight">
              {product.name}
            </h1>
          </div>
          {product.eta && (
            <p className="text-sm text-ink-muted">
              Estimasi tiba {product.eta.min_days}–{product.eta.max_days} hari.
            </p>
          )}
          <VariantPicker product={product} />
          <section
            aria-labelledby="description-title"
            className="space-y-3 border-t border-divider pt-6"
          >
            <h2 id="description-title" className="font-semibold">
              Tentang produk
            </h2>
            <div
              className="text-sm leading-relaxed text-ink-muted"
              dangerouslySetInnerHTML={{ __html: product.description_html }}
            />
          </section>
          {product.care_notes && (
            <section className="space-y-2">
              <h2 className="font-semibold">Petunjuk perawatan</h2>
              <p className="whitespace-pre-line text-sm leading-relaxed text-ink-muted">
                {product.care_notes}
              </p>
            </section>
          )}
          {product.return_policy_notes && (
            <section className="space-y-2">
              <h2 className="font-semibold">Catatan pengembalian</h2>
              <p className="whitespace-pre-line text-sm leading-relaxed text-ink-muted">
                {product.return_policy_notes}
              </p>
            </section>
          )}
        </div>
      </div>
      <details className="rounded-card border border-divider p-6">
        <summary className="min-h-touch cursor-pointer font-medium">
          Semua varian
        </summary>
        <ul className="divide-y divide-divider">
          {product.variants.map((variant) => (
            <li
              key={variant.id}
              className="flex flex-wrap justify-between gap-3 py-4 text-sm"
            >
              <span>{variant.label || variant.sku}</span>
              <span>
                {formatIdr(variant.price.amount_minor)} ·{" "}
                {availability(variant.available, product.kind)}
              </span>
            </li>
          ))}
        </ul>
      </details>
      <script
        type="application/ld+json"
        nonce={nonce}
        dangerouslySetInnerHTML={{
          __html: serializeJsonLd(productJsonLd(product)),
        }}
      />
    </div>
  );
}
