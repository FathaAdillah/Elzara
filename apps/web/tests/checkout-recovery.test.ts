import { isValidElement, type ReactElement, type ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  state: [] as unknown[], cursor: 0,
  createCheckout: vi.fn(), getCart: vi.fn(), shippingOptions: vi.fn(),
}));
vi.mock("react", async (original) => ({
  ...await original<typeof import("react")>(),
  useEffect: vi.fn(),
  useState(initial: unknown) {
    const index = mocks.cursor++;
    if (!(index in mocks.state)) mocks.state[index] = initial;
    return [mocks.state[index], (value: unknown) => { mocks.state[index] = value; }];
  },
}));
vi.mock("@elzara/ui", () => ({ Button: "button" }));
vi.mock("../lib/shop-api", () => ({
  createCheckout: mocks.createCheckout, getCart: mocks.getCart, shippingOptions: mocks.shippingOptions,
  getAddresses: vi.fn(), saveAddress: vi.fn(), selectShipping: vi.fn(),
}));
import { CheckoutPanel } from "../components/shop/checkout";
import { CommerceError } from "../lib/commerce";

type Props = { children?: ReactNode; onClick?: () => void };
function elements(node: ReactNode): ReactElement<Props>[] {
  if (Array.isArray(node)) return node.flatMap(elements);
  return isValidElement<Props>(node) ? [node, ...elements(node.props.children)] : [];
}
function text(node: ReactNode): string {
  if (Array.isArray(node)) return node.map(text).join("");
  if (isValidElement<Props>(node)) return text(node.props.children);
  return typeof node === "string" || typeof node === "number" ? String(node) : "";
}
function render() { mocks.cursor = 0; return CheckoutPanel(); }

describe("checkout recovery", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.state = [
      { items_hash: "old", groups: [{}], subtotal: { amount_minor: 400000 } }, [], "addr_saved",
      { id: "cs_old", subtotal: { amount_minor: 400000 }, shipping_estimate: { amount_minor: 52000 }, total: { amount_minor: 452000 }, snapshot: { cart: { groups: [] } }, expires_at: "2026-11-22T10:00:00Z" },
      { groups: [] }, { 0: "jne:REG" }, "", {}, false,
    ];
  });
  it.each(["CART_ITEM_UNAVAILABLE", "CART_EMPTY", "CHECKOUT_SNAPSHOT_STALE", "ADDRESS_INVALID", "NETWORK_FAILURE"])("removes old totals and refreshes the cart after %s", async (code) => {
    const error = code === "NETWORK_FAILURE" ? new Error("Koneksi terputus.") : new CommerceError({ code, title: code });
    const fresh = { items_hash: "fresh", groups: [], subtotal: { amount_minor: 0 } };
    mocks.createCheckout.mockRejectedValue(error);
    mocks.getCart.mockResolvedValue(fresh);
    const before = render();
    expect(text(before)).toContain("452.000");
    const click = elements(before).find((node) => node.props.onClick)?.props.onClick;
    expect(click).toBeDefined();
    click!();
    await vi.waitFor(() => expect(mocks.getCart).toHaveBeenCalledOnce());
    expect(mocks.createCheckout).toHaveBeenCalledWith("addr_saved", "old");
    expect(mocks.shippingOptions).not.toHaveBeenCalled();
    expect(mocks.state[3]).toBeUndefined();
    expect(mocks.state[4]).toBeUndefined();
    expect(mocks.state[5]).toEqual({});
    expect(mocks.state[0]).toEqual(fresh);
    const after = text(render());
    expect(after).not.toContain("452.000");
    expect(after).toContain(error.message);
  });
});
