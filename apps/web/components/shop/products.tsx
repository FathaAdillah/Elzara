import { Badge, Card, Grid } from "@elzara/ui";
import Link from "next/link";
import { availability, type Product } from "@/lib/catalog";
import { formatIdr } from "@/lib/money";
import { ProductImage } from "./product-image";

export function ModeBadge({ kind }: { kind: Product["kind"] }) {
  return (
    <Badge
      tone={kind === "rts" ? "ready" : kind === "pre_order" ? "po" : "quote"}
    >
      {kind === "rts"
        ? "RTS · Ready stock"
        : kind === "pre_order"
          ? "PO · Pre-order"
          : "Quote · Request"}
    </Badge>
  );
}

export function PriceBlock({
  amount,
  large = false,
}: {
  amount: number;
  large?: boolean;
}) {
  return (
    <p
      className={
        large
          ? "text-3xl font-semibold tracking-tight"
          : "text-lg font-semibold"
      }
    >
      {formatIdr(amount)}
    </p>
  );
}

export function ProductCard({ product }: { product: Product }) {
  return (
    <Card
      as="article"
      padding="none"
      className="overflow-hidden border border-divider shadow-none"
    >
      <Link
        href={`/catalog/${product.slug}`}
        className="group block rounded-card"
        aria-label={`Lihat ${product.name}`}
      >
        <ProductImage
          src={product.primary_media?.url}
          alt={product.primary_media?.alt || product.name}
        />
        <div className="grid gap-3 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <ModeBadge kind={product.kind} />
            <span className="text-xs text-ink-muted">
              {availability(product.available, product.kind)}
            </span>
          </div>
          <div>
            <p className="mb-1 text-xs text-ink-muted">
              {product.brand || product.category?.name || "Koleksi"}
            </p>
            <h3 className="font-medium group-hover:underline">
              {product.name}
            </h3>
          </div>
          <PriceBlock amount={product.price.amount_minor} />
        </div>
      </Link>
    </Card>
  );
}

export function ProductGrid({ products }: { products: Product[] }) {
  return (
    <Grid columns={{ base: 1, sm: 2, md: 3, lg: 4 }} gap="md">
      {products.map((product) => (
        <ProductCard key={product.id} product={product} />
      ))}
    </Grid>
  );
}

export function CatalogMessage({ children }: { children: React.ReactNode }) {
  return (
    <div
      role="status"
      className="rounded-card border border-divider bg-surface-muted p-8 text-sm leading-relaxed"
    >
      {children}
    </div>
  );
}
