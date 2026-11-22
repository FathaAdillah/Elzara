import type { ProductDetail } from "@/lib/catalog";
import { ProductImage } from "./product-image";

export function Gallery({ product }: { product: ProductDetail }) {
  const first = product.primary_media ?? product.media[0];
  const rest = product.media.filter((media) => media.id !== first?.id);
  return (
    <section aria-label={`Galeri ${product.name}`} className="space-y-4">
      <ProductImage
        src={first?.url}
        alt={first?.alt || product.name}
        priority
      />
      {rest.length > 0 && (
        <div className="grid grid-cols-3 gap-3">
          {rest.map((media) => (
            <ProductImage
              key={media.id}
              src={media.url}
              alt={media.alt || product.name}
            />
          ))}
        </div>
      )}
    </section>
  );
}
