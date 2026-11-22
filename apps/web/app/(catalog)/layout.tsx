export default function CatalogLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen flex-col bg-surface">
      <main id="konten" tabIndex={-1} className="mx-auto w-full max-w-content flex-1 p-6">
        {children}
      </main>
    </div>
  );
}
