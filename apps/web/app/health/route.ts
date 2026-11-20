export const dynamic = "force-dynamic";

/**
 * Probe container (Doc 01 §11.1 `healthcheck` tiap service). Hanya melaporkan proses
 * Node ini hidup — konektivitas API TIDAK diuji di sini supaya probe
 * tidak pernah berbohong soal downstream.
 */
export async function GET(): Promise<Response> {
  return Response.json({ status: "ok", app: "web" });
}
