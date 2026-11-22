/** Replace these local asset slots when the final artwork is ready. */
export const storefront = {
  logo: "",
  // Optional category artwork keyed by the API category slug.
  categoryImages: {} as Record<string, string>,
  banners: [
    {
      eyebrow: "PILIHAN KECIL, BAHAGIA BESAR",
      title: "Temukan favorit barumu.",
      description:
        "Dari koleksi impian sampai teman sehari-hari. Yuk, temukan sesuatu yang kamu suka.",
      image: "/brand/banner-placeholder.svg",
      href: "/catalog",
      action: "Belanja sekarang",
    },
    {
      eyebrow: "TUNGGU YANG PALING KAMU SUKA",
      title: "Favoritmu, selangkah lebih dekat.",
      description:
        "Jelajahi pilihan pre-order dan temukan koleksi yang ingin kamu bawa pulang.",
      image: "/brand/banner-placeholder.svg",
      href: "/catalog?kind=pre_order",
      action: "Jelajahi pre-order",
    },
  ],
};
