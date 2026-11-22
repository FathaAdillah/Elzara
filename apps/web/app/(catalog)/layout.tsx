export default function CatalogLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="flex min-h-screen flex-col">
      <main id="konten" tabIndex={-1} className="store-container flex-1 py-6">
        {children}
      </main>
    </div>
  );
}
