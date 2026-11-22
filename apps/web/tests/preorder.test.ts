import { describe, expect, it } from "vitest";
import { canEnroll, countdownLabel, remainingSeconds } from "../lib/preorder";
import { CommerceError } from "../lib/commerce";

const now = "2026-11-22T10:00:00Z";
const item = { status: "open", remaining: 5, opens_at: now, closes_at: "2026-11-23T10:00:00Z" };

describe("preorder availability and server countdown", () => {
  it("counts from API now and monotonic elapsed time, not wall clock", () => {
    expect(remainingSeconds(now, item.closes_at, 0)).toBe(86400);
    expect(remainingSeconds(now, item.closes_at, 1000)).toBe(86399);
    expect(countdownLabel(86401)).toBe("1 hari 0 jam 0 menit 1 detik");
    expect(canEnroll("open", item, now)).toBe(true);
  });
  it("clamps expired windows and rejects empty or closed items and batches", () => {
    expect(remainingSeconds(now, item.closes_at, 86401000)).toBe(0);
    expect(countdownLabel(0)).toContain("ditutup");
    expect(canEnroll("open", { ...item, remaining: 0 }, now)).toBe(false);
    expect(canEnroll("closed", item, now)).toBe(false);
    expect(canEnroll("open", { ...item, status: "closed" }, now)).toBe(false);
    expect(canEnroll("open", item, now, 86400000)).toBe(false);
    expect(canEnroll("open", { ...item, opens_at: item.closes_at }, now)).toBe(false);
  });
  it.each([
    ["BATCH_QUOTA_EXHAUSTED", "Kuota batch habis"],
    ["BATCH_NOT_OPEN", "Batch belum dibuka atau sudah ditutup"],
    ["BATCH_ACCOUNT_LIMIT_EXCEEDED", "batas per akun"],
    ["CHECKOUT_MIXED_MODES", "Pisahkan produk"],
  ])("explains %s in Indonesian", (code, message) => {
    expect(new CommerceError({ code }).message).toContain(message);
  });
});
