"use client";

import { useEffect, useRef, type ReactNode } from "react";
import { ShopIcon } from "./shop-icon";

export function CategoryMenu({ children }: { children: ReactNode }) {
  const menu = useRef<HTMLDetailsElement>(null);

  useEffect(() => {
    function dismissOutside(event: PointerEvent) {
      if (menu.current && !menu.current.contains(event.target as Node)) {
        menu.current.open = false;
      }
    }
    function dismissEscape(event: KeyboardEvent) {
      if (event.key === "Escape" && menu.current?.open) {
        const focusWasInside = menu.current.contains(document.activeElement);
        menu.current.open = false;
        if (focusWasInside) menu.current.querySelector("summary")?.focus();
      }
    }
    document.addEventListener("pointerdown", dismissOutside);
    document.addEventListener("keydown", dismissEscape);
    return () => {
      document.removeEventListener("pointerdown", dismissOutside);
      document.removeEventListener("keydown", dismissEscape);
    };
  }, []);

  return (
    <details
      ref={menu}
      className="store-category-menu"
      onClick={(event) => {
        if ((event.target as Element).closest("a") && menu.current) {
          menu.current.open = false;
        }
      }}
    >
      <summary>
        <ShopIcon name="menu" />
        Semua kategori
      </summary>
      <div className="store-category-dropdown">{children}</div>
    </details>
  );
}
