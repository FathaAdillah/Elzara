import { describe, expect, it } from "vitest";
import { loginDestination } from "../lib/login-destination";

describe("login return destination", () => {
  it("returns to the requested storefront path including its query", () => {
    expect(loginDestination("/cart")).toBe("/cart");
    expect(loginDestination("/checkout?step=address#form")).toBe("/checkout?step=address#form");
  });
  it("falls back for missing, external, protocol-relative and malformed paths", () => {
    for (const value of [null, "", "https://evil.test", "//evil.test", "cart", "/\\evil.test", "/\n/evil.test", "javascript:alert(1)"]) {
      expect(loginDestination(value)).toBe("/account");
    }
  });
});
