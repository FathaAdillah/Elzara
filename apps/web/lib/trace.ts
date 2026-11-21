/**
 * W3C Trace Context (Doc 01 §12): storefront melanjutkan `traceparent` dari edge atau memulai
 * trace baru, lalu meneruskannya ke API sebagai span anak supaya web → api → job satu trace_id.
 */
const TRACEPARENT = /^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/;

function hex(bytes: number): string {
  let id = "";
  do {
    id = Array.from(crypto.getRandomValues(new Uint8Array(bytes)), (b) => b.toString(16).padStart(2, "0")).join("");
  } while (/^0+$/.test(id));
  return id;
}

/** `traceparent` valid untuk request ini: lanjutkan trace masuk dengan span baru, atau trace baru. */
export function childTraceparent(incoming: string | null | undefined): string {
  const [, parentTrace = "", parentSpan = "", flags = "01"] = (incoming && TRACEPARENT.exec(incoming.trim().toLowerCase())) || [];
  const traceId = parentTrace && !/^0+$/.test(parentTrace) && !/^0+$/.test(parentSpan) ? parentTrace : hex(16);
  return `00-${traceId}-${hex(8)}-${flags}`;
}
