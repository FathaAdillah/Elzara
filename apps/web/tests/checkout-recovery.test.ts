import { isValidElement, type ReactElement, type ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  state: [] as unknown[], cursor: 0,
  createCheckout: vi.fn(), getCart: vi.fn(), shippingOptions: vi.fn(),
  placeCheckoutOrder: vi.fn(), simulatePayment: vi.fn(),
}));
vi.mock("react", async (original) => ({
  ...await original<typeof import("react")>(),
  useEffect: vi.fn(),
  useRef(initial: unknown) {
    const index = mocks.cursor++;
    if (!(index in mocks.state)) mocks.state[index] = { current: initial };
    return mocks.state[index];
  },
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
  placeCheckoutOrder: mocks.placeCheckoutOrder, simulatePayment: mocks.simulatePayment,
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
  it("reuses the reservation key after an ambiguous network failure and then simulates payment", async () => {
    Object.assign(mocks.state[3] as object, {
      status: "open", fake_payment_available: true,
      snapshot: { cart: { groups: [{ mode: "rts", items: [{ id: "item_test", name: "Produk uji", quantity: 1, line_total: { amount_minor: 400000 } }] }] } },
    });
    mocks.state[4] = { groups: [{ group_index: 0, mode: "rts", options: [{ courierCode: "jne", serviceCode: "REG", serviceName: "Reguler", amountMinor: 52000, etaMinDays: null }] }] };
    mocks.placeCheckoutOrder.mockRejectedValueOnce(new Error("Koneksi terputus.")).mockResolvedValueOnce({
      id: "ord_test", total: { amount_minor: 500000 }, reservation_expires_at: "2026-11-22T10:00:00Z",
      repriced: true, payment_intent: { id: "pi_test" },
    });
    mocks.simulatePayment.mockResolvedValueOnce({ order_id: "ord_test", status: "paid" });
    const press = (label: string) => elements(render()).find((node) => text(node) === label && node.props.onClick)?.props.onClick?.();
    expect(text(render())).toContain("452.000");
    expect(text(render())).toContain("JNE · Reguler");
    press("Reservasi stok untuk simulasi");
    await vi.waitFor(() => expect(text(render())).toContain("Koneksi terputus."));
    press("Reservasi stok untuk simulasi");
    await vi.waitFor(() => expect(text(render())).toContain("Harga atau ongkir berubah"));
    const reserved = text(render());
    expect(reserved).toContain("Produk uji × 1");
    expect(reserved).toMatch(/Total pesanan: .*500\.000/);
    for (const quote of ["400.000", "52.000", "452.000"]) expect(reserved).not.toContain(quote);
    expect(reserved).not.toContain("JNE · Reguler");
    expect(mocks.placeCheckoutOrder.mock.calls).toHaveLength(2);
    expect(mocks.placeCheckoutOrder.mock.calls[0]).toEqual(mocks.placeCheckoutOrder.mock.calls[1]);
    press("Simulasikan pembayaran");
    await vi.waitFor(() => expect(text(render())).toContain("Pembayaran simulasi berhasil"));
  });
});
