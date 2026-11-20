/**
 * Tailwind v4 CSS-first: tidak ada `tailwind.config.*` di app ini.
 * Plugin PostCSS hanya meneruskan; seluruh nilai tema datang dari `packages/ui`
 * lewat `app/globals.css` (Doc 04 §2.1). Menambah plugin warna di sini = membuat
 * sumber kebenaran kedua untuk token (Doc 04 §12).
 */
export default {
  plugins: {
    "@tailwindcss/postcss": {},
  },
};
