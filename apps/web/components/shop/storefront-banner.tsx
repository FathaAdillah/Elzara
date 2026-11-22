"use client";

import Image from "next/image";
import Link from "next/link";
import { useState } from "react";
import { storefront } from "@/config/storefront";
import { ShopIcon } from "./shop-icon";

export function StorefrontBanner({ compact = false }: { compact?: boolean }) {
  const [active, setActive] = useState(0);
  return (
    <section
      className={`store-banner${compact ? " store-banner--compact" : ""}`}
      aria-label="Pilihan belanja"
      aria-roledescription={compact ? undefined : "carousel"}
    >
      {(compact ? storefront.banners.slice(0, 1) : storefront.banners).map(
        (banner, index) => (
          <div
            key={banner.href}
            className="store-banner-slide"
            hidden={active !== index}
            role="group"
            aria-roledescription="slide"
            aria-label={`${index + 1} dari ${storefront.banners.length}`}
          >
            <Image
              className="store-banner-art"
              src={banner.image}
              alt=""
              fill
              sizes={
                compact
                  ? "(max-width: 1240px) 100vw, 1240px"
                  : "(max-width: 1000px) 100vw, 1000px"
              }
              priority={index === 0}
            />
            <div className="store-banner-copy">
              <p className="store-eyebrow">{banner.eyebrow}</p>
              <h2>{banner.title}</h2>
              <p className="store-banner-description">{banner.description}</p>
              <Link className="store-button" href={banner.href}>
                {banner.action}
                <ShopIcon name="arrow" />
              </Link>
            </div>
          </div>
        ),
      )}
      {!compact && (
        <div className="store-banner-dots" aria-label="Pilih banner">
          {storefront.banners.map((banner, index) => (
            <button
              key={banner.href}
              type="button"
              aria-label={`Tampilkan banner ${index + 1}`}
              aria-pressed={active === index}
              onClick={() => setActive(index)}
            >
              <span />
            </button>
          ))}
        </div>
      )}
    </section>
  );
}
