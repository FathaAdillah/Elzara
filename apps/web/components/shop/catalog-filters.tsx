import { Button, Input } from "@elzara/ui";
import { catalogHref, type Category, type SearchParams } from "@/lib/catalog";

export function SearchBar({ value }: { value: string }) {
  return (
    <Input
      label="Cari produk"
      name="q"
      type="search"
      placeholder="Nama produk, brand, atau seri…"
      defaultValue={value}
      maxLength={200}
    />
  );
}

const selectClass =
  "w-full rounded-card border border-control-border bg-surface px-3 text-sm";

export function FacetPanel({
  categories,
  params,
}: {
  categories: Category[];
  params: SearchParams;
}) {
  const value = (name: string) =>
    typeof params[name] === "string" ? (params[name] as string) : "";
  return (
    <form
      key={catalogHref(params)}
      action="/catalog"
      method="get"
      className="space-y-6"
    >
      {["brand", "collection"].map((name) => value(name) && (
        <input key={name} type="hidden" name={name} value={value(name)} />
      ))}
      <SearchBar value={value("q")} />
      <fieldset className="space-y-4">
        <legend className="mb-4 text-xs font-semibold uppercase tracking-widest text-ink-muted">
          Saring pilihan
        </legend>
        <label className="grid gap-2 font-medium">
          Mode
          <select
            className={selectClass}
            name="kind"
            defaultValue={value("kind")}
          >
            <option value="">Semua mode</option>
            <option value="rts">Ready stock</option>
            <option value="pre_order">Pre-order</option>
            <option value="request_order">Request / Quote</option>
          </select>
        </label>
        <label className="grid gap-2 font-medium">
          Kategori
          <select
            className={selectClass}
            name="category"
            defaultValue={value("category")}
          >
            <option value="">Semua kategori</option>
            {categories.map((category) => (
              <option key={category.slug} value={category.slug}>
                {category.parent ? "— " : ""}
                {category.name}
              </option>
            ))}
          </select>
        </label>
        <Input
          label="Harga minimum (Rp)"
          name="price_min"
          type="number"
          min="0"
          step="1"
          inputMode="numeric"
          defaultValue={value("price_min")}
          placeholder="0"
        />
        <Input
          label="Harga maksimum (Rp)"
          name="price_max"
          type="number"
          min="0"
          step="1"
          inputMode="numeric"
          defaultValue={value("price_max")}
          placeholder="Tanpa batas"
          hint="Masukkan rupiah utuh, tanpa pemisah ribuan."
        />
        <label className="grid gap-2 font-medium">
          Ketersediaan
          <select
            className={selectClass}
            name="in_stock"
            defaultValue={value("in_stock")}
          >
            <option value="">Semua stok</option>
            <option value="true">Tersedia</option>
            <option value="false">Stok habis</option>
          </select>
        </label>
        <label className="grid gap-2 font-medium">
          Urutkan
          <select
            className={selectClass}
            name="sort"
            defaultValue={value("sort") || "newest"}
          >
            <option value="newest">Terbaru</option>
            <option value="price_asc">Harga terendah</option>
            <option value="price_desc">Harga tertinggi</option>
            <option value="relevance">Paling relevan (isi pencarian)</option>
          </select>
        </label>
      </fieldset>
      <Button type="submit" block>
        Terapkan filter <span aria-hidden="true">↗</span>
      </Button>
      <a
        href="/catalog"
        className="flex min-h-touch items-center justify-center text-sm underline underline-offset-4"
      >
        Hapus semua filter
      </a>
    </form>
  );
}
