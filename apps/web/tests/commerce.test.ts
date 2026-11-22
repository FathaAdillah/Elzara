import { describe, expect, it } from "vitest";
import { CommerceError, rupiah, validQuantity } from "../lib/commerce";
describe("commerce helpers", () => {
  it("explains known checkout failures without displaying problem codes", () => {
    for (const code of ["CHECKOUT_SNAPSHOT_STALE", "CHECKOUT_SESSION_EXPIRED", "CART_ITEM_UNAVAILABLE", "CART_EMPTY", "ADDRESS_INVALID", "SHIPPING_WEIGHT_MISSING", "SHIPPING_PROVIDER_UNAVAILABLE", "SHIPPING_QUOTES_REQUIRED", "SHIPPING_SELECTION_INCOMPLETE", "SHIPPING_SELECTION_INVALID", "SHIPPING_UNKNOWN"]) {
      const error = new CommerceError({ code, title: code });
      expect(error.message).not.toBe(code);
      expect(error.message.length).toBeGreaterThan(20);
    }
  });
  it("uses friendly details or titles and safely handles unknown codes and outages", () => {
    expect(new CommerceError({ code: "NEW_ERROR", title: "NEW_ERROR", detail: "Silakan coba besok." }).message).toBe("Silakan coba besok.");
    expect(new CommerceError({ title: "Permintaan ditolak" }).message).toBe("Permintaan ditolak");
    expect(new CommerceError({ title: "NEW_ERROR" }).message).not.toBe("NEW_ERROR");
    expect(new CommerceError({ status: 503, title: "SERVER_ERROR" }).message).toContain("sementara");
  });
  it("validates whole quantities within API limits", () => { expect(validQuantity("1")).toBe(1); expect(validQuantity("99")).toBe(99); });
  it("rejects empty, fractional and invalid quantities", () => { for (const v of ["", "0", "100", "1.5", "-1", "NaN", "2x"]) expect(validQuantity(v)).toBeUndefined(); });
  it("formats integer rupiah and exposes server validation", () => { expect(rupiah(12000)).toContain("12.000"); expect(rupiah(0)).toContain("0"); const e = new CommerceError({ title: "Alamat invalid", errors: [{ pointer: "/phone", detail: "invalid" }] }); expect(e.message).toBe("Alamat invalid"); expect(e.problem.errors?.[0]?.pointer).toBe("/phone"); expect(new CommerceError({}).message).not.toBe(""); });
});
