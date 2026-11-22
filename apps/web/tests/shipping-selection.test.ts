import { beforeEach, describe, expect, it, vi } from "vitest";
const mocks = vi.hoisted(() => ({ put: vi.fn(), csrf: vi.fn() }));
vi.mock("../lib/api", () => ({ api: { PUT: mocks.put } }));
vi.mock("../lib/session", () => ({ ensureCsrfCookie: mocks.csrf, xsrfHeader: () => ({ "X-XSRF-TOKEN": "token" }) }));
import { selectShipping } from "../lib/shop-api";
import { CommerceError } from "../lib/commerce";
describe("shipping selection", () => {
  beforeEach(() => { vi.clearAllMocks(); });
  it("sends the complete selection with a new idempotency key and returns server totals", async () => {
    const saved = { shipping_estimate: { amount_minor: 33000 }, total: { amount_minor: 133000 } };
    mocks.put.mockResolvedValue({ data: saved, response: new Response(null, { status: 200 }) });
    const selections = [{ group_index: 0, courier_code: "jne", service_code: "YES" }];
    expect(await selectShipping("cs_test", selections)).toEqual(saved);
    await selectShipping("cs_test", selections);
    const options = mocks.put.mock.calls[0]?.[1];
    expect(options.body).toEqual({ selections });
    expect(options.params.path.id).toBe("cs_test");
    expect(options.params.header["X-XSRF-TOKEN"]).toBe("token");
    expect(options.params.header["Idempotency-Key"]).not.toBe(mocks.put.mock.calls[1]?.[1].params.header["Idempotency-Key"]);
    expect(options.cache).toBe("no-store");
    expect(mocks.csrf).toHaveBeenCalledTimes(2);
  });
  it("surfaces rejected or empty selections without inventing totals", async () => {
    mocks.put.mockResolvedValue({ error: { title: "Pilihan tidak valid", code: "SHIPPING_SELECTION_INVALID" }, response: new Response(null, { status: 422 }) });
    await expect(selectShipping("cs_test", [])).rejects.toBeInstanceOf(CommerceError);
  });
});
