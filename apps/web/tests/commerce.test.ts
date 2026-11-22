import { describe, expect, it } from "vitest";
import { CommerceError, rupiah, validQuantity } from "../lib/commerce";
describe("commerce helpers", () => {
  it("validates whole quantities within API limits", () => { expect(validQuantity("1")).toBe(1); expect(validQuantity("99")).toBe(99); });
  it("rejects empty, fractional and invalid quantities", () => { for (const v of ["", "0", "100", "1.5", "-1", "NaN", "2x"]) expect(validQuantity(v)).toBeUndefined(); });
  it("formats integer rupiah and exposes server validation", () => { expect(rupiah(12000)).toContain("12.000"); expect(rupiah(0)).toContain("0"); const e = new CommerceError({ title: "Alamat invalid", errors: [{ pointer: "/phone", detail: "invalid" }] }); expect(e.message).toBe("Alamat invalid"); expect(e.problem.errors?.[0]?.pointer).toBe("/phone"); expect(new CommerceError({}).message).not.toBe(""); });
});
