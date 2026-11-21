"use client";

import type { MouseEvent } from "react";

export function SkipLink() {
  function skipToContent(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const content = document.getElementById("konten");
    if (!content) return;

    // Move keyboard focus without adding a fragment entry to the router's history.
    event.preventDefault();
    content.focus({ preventScroll: true });
    content.scrollIntoView({ block: "start" });
  }

  return (
    <a href="#konten" onClick={skipToContent} className="sr-only focus:not-sr-only focus:text-brand-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring">
      Ke konten
    </a>
  );
}
