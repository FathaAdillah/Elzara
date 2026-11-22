"use client";

import { useState } from "react";
import { Button } from "@elzara/ui";
import {
  availability,
  hasCombination,
  resolveVariant,
  type ProductDetail,
} from "@/lib/catalog";
import { PriceBlock } from "./products";

export function VariantPicker({ product }: { product: ProductDetail }) {
  const options = product.options ?? [];
  const [selected, setSelected] = useState<Record<string, string>>(
    product.variants[0]?.attributes ?? {},
  );
  const [selectedVariantId, setSelectedVariantId] = useState(
    product.variants[0]?.id,
  );
  const complete = options.every(
    (option) => selected[option.name] !== undefined,
  );
  const variant =
    options.length === 0
      ? product.variants.find((candidate) => candidate.id === selectedVariantId)
      : complete
        ? resolveVariant(product.variants, selected)
        : undefined;
  return (
    <section aria-label="Pilih varian" className="space-y-6">
      <div
        aria-live="polite"
        aria-atomic="true"
        className="space-y-2 border-y border-divider py-6"
      >
        <PriceBlock
          amount={variant?.price.amount_minor ?? product.price.amount_minor}
          large
        />
        <p className="text-sm font-medium">
          {variant
            ? availability(variant.available, product.kind)
            : "Pilih semua opsi untuk melihat ketersediaan."}
        </p>
        <p className="text-xs text-ink-muted">SKU: {variant?.sku ?? "—"}</p>
        {variant && variant.available === 0 && (
          <p className="text-xs text-ink-muted">
            {product.kind === "rts"
              ? "Varian ini sedang habis."
              : "Belum ada stok siap kirim untuk varian ini."}
          </p>
        )}
      </div>
      {options.length === 0 && product.variants.length > 1 && (
        <fieldset className="space-y-3">
          <legend className="mb-3 text-sm font-semibold">Varian</legend>
          <div className="flex flex-wrap gap-3">
            {product.variants.map((candidate) => (
              <label
                key={candidate.id}
                className={`inline-flex min-h-touch items-center gap-2 rounded-badge border px-4 text-sm ${candidate.id === selectedVariantId ? "border-brand-text bg-brand-ink font-medium" : "border-control-border"}`}
              >
                <input
                  type="radio"
                  name="variant"
                  value={candidate.id}
                  checked={candidate.id === selectedVariantId}
                  onChange={() => setSelectedVariantId(candidate.id)}
                  className="accent-brand-text"
                />
                <span>
                  {candidate.label || candidate.sku}
                  {candidate.available === 0 ? " · Habis" : ""}
                </span>
              </label>
            ))}
          </div>
        </fieldset>
      )}
      {options.map((option) => (
        <fieldset key={option.name} className="space-y-3">
          <legend className="mb-3 text-sm font-semibold">{option.name}</legend>
          <div className="flex flex-wrap gap-3">
            {option.values.map((value) => {
              const possible = hasCombination(
                product.variants,
                selected,
                option.name,
                value,
              );
              const candidate = resolveVariant(product.variants, {
                ...selected,
                [option.name]: value,
              });
              return (
                <label
                  key={value}
                  className={`relative inline-flex min-h-touch min-w-touch items-center gap-2 rounded-badge border px-4 text-sm ${!possible ? "border-divider text-ink-muted opacity-60" : selected[option.name] === value ? "border-brand-text bg-brand-ink font-medium" : "border-control-border"}`}
                >
                  <input
                    type="radio"
                    name={`option-${option.name}`}
                    value={value}
                    checked={selected[option.name] === value}
                    disabled={!possible}
                    onChange={() =>
                      setSelected({ ...selected, [option.name]: value })
                    }
                    className="accent-brand-text"
                  />
                  <span>
                    {value}
                    {!possible
                      ? " · Tidak tersedia"
                      : candidate?.available === 0
                        ? " · Habis"
                        : ""}
                  </span>
                </label>
              );
            })}
          </div>
        </fieldset>
      ))}
      {options.length > 0 && (
        <Button variant="ghost" size="sm" onClick={() => setSelected({})}>
          Atur ulang pilihan
        </Button>
      )}
      <div className="space-y-3">
        <Button disabled block aria-describedby="cart-coming-soon">
          Tambah ke keranjang
        </Button>
        <p
          id="cart-coming-soon"
          className="text-xs leading-relaxed text-ink-muted"
        >
          Fitur keranjang segera hadir. Harga dan stok akan dikonfirmasi kembali
          saat checkout.
        </p>
      </div>
      <noscript>
        <p className="text-sm">
          Aktifkan JavaScript untuk memilih varian. Semua varian tersedia pada
          daftar di bawah.
        </p>
      </noscript>
    </section>
  );
}
