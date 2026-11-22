import { isValidElement, type ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({ getBatch: vi.fn(), getBatches: vi.fn() }));
vi.mock("../lib/preorder-api", () => mocks);
vi.mock("next/navigation", () => ({ notFound: () => { throw new Error("NOT_FOUND"); } }));
import BatchPage from "../app/preorder/[batch]/page";
import ListPage from "../app/preorder/page";

function text(node: ReactNode): string {
  if (Array.isArray(node)) return node.map(text).join("");
  if (isValidElement<{ children?: ReactNode }>(node)) return text(node.props.children);
  return typeof node === "string" || typeof node === "number" ? String(node) : "";
}
const batch = { id: "batch_one", name: "Nova batch", status: "open", eta_min_date: "2026-12-01", eta_max_date: "2026-12-05", deposit_policy: { kind: "percent", value: 5000 }, currency: "IDR", closes_at: "2026-11-30T10:00:00Z", items: [] };
const render = () => BatchPage({ params: Promise.resolve({ batch: "batch_one" }) });

describe("preorder pages", () => {
  beforeEach(() => vi.resetAllMocks());
  it("renders server batch data and deposit policy", async () => {
    mocks.getBatch.mockResolvedValue({ data: batch, response: { status: 200 } });
    const content = text(await render());
    expect(mocks.getBatch).toHaveBeenCalledWith("batch_one");
    expect(content).toContain("Nova batch");
    expect(content).toContain("2026-12-01");
    expect(content).toContain("50%");
    expect(content).toContain("ongkir ditagih saat release");
  });
  it("uses not-found UI for 404", async () => {
    mocks.getBatch.mockResolvedValue({ response: { status: 404 } });
    await expect(render()).rejects.toThrow("NOT_FOUND");
  });
  it("shows an outage instead of a missing batch for network errors", async () => {
    mocks.getBatch.mockResolvedValue(undefined);
    expect(text(await render())).toContain("Batch sedang tidak tersedia");
  });
  it("renders empty, populated and unavailable batch lists", async () => {
    mocks.getBatches.mockResolvedValueOnce({ data: { data: [] } });
    expect(text(await ListPage())).toContain("Belum ada batch terbuka");
    mocks.getBatches.mockResolvedValueOnce({ data: { data: [batch] } });
    expect(text(await ListPage())).toContain("Nova batch");
    mocks.getBatches.mockResolvedValueOnce(undefined);
    expect(text(await ListPage())).toContain("Daftar batch sedang tidak tersedia");
  });
});
