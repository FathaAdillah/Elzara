export function loginDestination(next: string | null): string {
  if (!next?.startsWith("/") || next.startsWith("//") || /[\\\u0000-\u0020\u007f]/.test(next)) return "/account";
  return next;
}
