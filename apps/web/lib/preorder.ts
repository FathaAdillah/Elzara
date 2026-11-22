export function remainingSeconds(now: string, closes: string, elapsedMs: number): number {
  return Math.max(0, Math.ceil((Date.parse(closes) - Date.parse(now) - elapsedMs) / 1000));
}

export function countdownLabel(seconds: number): string {
  if (seconds <= 0) return "Jendela pemesanan ditutup";
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor(seconds % 86400 / 3600);
  const minutes = Math.floor(seconds % 3600 / 60);
  return `${days} hari ${hours} jam ${minutes} menit ${seconds % 60} detik`;
}

export function canEnroll(batchStatus: string, item: { status: string; remaining: number; opens_at: string; closes_at: string }, serverNow: string, elapsedMs = 0): boolean {
  const now = Date.parse(serverNow) + elapsedMs;
  return batchStatus === "open" && item.status === "open" && item.remaining > 0 && now >= Date.parse(item.opens_at) && now < Date.parse(item.closes_at);
}
