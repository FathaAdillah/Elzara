import { Badge } from "@elzara/ui";
import Link from "next/link";
import { availability, type Product } from "@/lib/catalog";
import { formatIdr } from "@/lib/money";
import { ProductImage } from "./product-image";
import { ShopIcon } from "./shop-icon";

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
    <article className="store-product-card">
      <Link
        href={`/catalog/${product.slug}`}
        className="store-product-image"
        aria-label={`Lihat ${product.name}`}
      >
        <ProductImage
          src={product.primary_media?.url}
          alt={product.primary_media?.alt || product.name}
          inset={false}
          sizes="(max-width: 760px) 45vw, 190px"
        />
        <span className="store-product-badge">
          <ModeBadge kind={product.kind} />
        </span>
      </Link>
      <div className="store-product-copy">
        <div>
          <small>{product.brand || product.category?.name || "Koleksi"}</small>
          <h3>
            <Link href={`/catalog/${product.slug}`}>{product.name}</Link>
          </h3>
        </div>
        <p className="store-product-availability">
          <ShopIcon name="box" />
          {availability(product.available, product.kind)}
        </p>
        <div className="store-product-price">
          <PriceBlock amount={product.price.amount_minor} />
        </div>
        <Link
          href={`/catalog/${product.slug}`}
          className="store-product-detail"
          aria-label={`Lihat detail ${product.name}`}
        >
          Lihat detail
          <ShopIcon name="arrow" />
        </Link>
      </div>
    </article>
  );
}

export function ProductGrid({ products }: { products: Product[] }) {
  return (
    <div className="store-product-grid">
      {products.map((product) => (
        <ProductCard key={product.id} product={product} />
      ))}
    </div>
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
