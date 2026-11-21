/**
 * Layout grup katalog. SiteHeader / MegaNav / SearchBar+FacetPanel / Footer adalah
 * komponen `shop/` yang didaftarkan Doc 04 §5 dan dibangun Sprint 2 — bukan diarahkan
 * dari kerangka ini. Yang ditahan di sini hanya landmark agar struktur aksesibilitas
 * (skip-link di `app/layout.tsx` -> `#konten`) sudah benar sejak awal (Doc 04 §5.2).
 */
export default function CatalogLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen flex-col bg-surface">
      <main id="konten" tabIndex={-1} className="mx-auto w-full max-w-content flex-1 p-6">
        {children}
      </main>
    </div>
  );
}
