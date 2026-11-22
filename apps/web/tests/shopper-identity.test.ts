import { afterEach, describe, expect, it, vi } from "vitest";
import {
  SHOPPER_IP_HEADER,
  STOREFRONT_KEY_HEADER,
  shopperIdentityHeaders,
} from "../lib/shopper-identity";

const { headers } = vi.hoisted(() => ({ headers: vi.fn() }));
vi.mock("next/headers", () => ({ headers }));

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.resetModules();
  headers.mockReset();
});

describe("shopper identity", () => {
  it("forwards the edge-written shopper IP with the server key", () => {
    expect(
      shopperIdentityHeaders(
        new Headers({ "x-real-ip": " 203.0.113.10 " }),
        "k".repeat(40),
      ),
    ).toEqual({
      [STOREFRONT_KEY_HEADER]: "k".repeat(40),
      [SHOPPER_IP_HEADER]: "203.0.113.10",
    });
  });

  it("sends nothing without a key or a shopper IP", () => {
    expect(
      shopperIdentityHeaders(
        new Headers({ "x-real-ip": "203.0.113.10" }),
        undefined,
      ),
    ).toEqual({});
    expect(
      shopperIdentityHeaders(new Headers({ "x-real-ip": "203.0.113.10" }), ""),
    ).toEqual({});
    expect(
      shopperIdentityHeaders(
        new Headers({ "x-forwarded-for": "203.0.113.10" }),
        "k".repeat(40),
      ),
    ).toEqual({});
  });

  it("adds the headers to server-side API calls", async () => {
    vi.stubEnv("API_INTERNAL_URL", "http://nginx:8080");
    vi.stubEnv("ELZARA_STOREFRONT_PROXY_KEY", "k".repeat(40));
    headers.mockResolvedValue(new Headers({ "x-real-ip": "198.51.100.20" }));
    const fetch = vi.fn(async (_request: Request) =>
      Response.json({ data: [] }),
    );
    vi.stubGlobal("fetch", fetch);
    const { api } = await import("../lib/api");
    await api.GET("/categories");
    const sent = fetch.mock.lastCall![0];
    expect(sent.url).toBe("http://nginx:8080/api/v1/categories");
    expect(sent.headers.get(STOREFRONT_KEY_HEADER)).toBe("k".repeat(40));
    expect(sent.headers.get(SHOPPER_IP_HEADER)).toBe("198.51.100.20");
  });
});
