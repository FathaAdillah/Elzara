"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { api, ensureCsrfCookie, socialLoginUrl, xsrfHeader } from "@/lib/session";

type Mode = "login" | "register";

/**
 * Masuk / daftar storefront (P0.11). Keputusan otentikasi tetap di Laravel; halaman ini
 * hanya mengirim kredensial ke `/auth/login` atau `/auth/register` dan tidak menyimpan
 * apa pun di localStorage.
 */
export default function LoginPage() {
  const router = useRouter();
  const [mode, setMode] = useState<Mode>("login");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const email = String(form.get("email") ?? "");
    const password = String(form.get("password") ?? "");
    setBusy(true);
    setError(null);
    try {
      await ensureCsrfCookie();
      const result =
        mode === "login"
          ? await api.POST("/auth/login", { body: { email, password }, params: { header: xsrfHeader() } })
          : await api.POST("/auth/register", {
              body: { name: String(form.get("name") ?? ""), email, password },
              params: { header: xsrfHeader() },
            });
      if (result.error) {
        setError(result.response.status === 401 ? "Email atau kata sandi salah." : "Permintaan ditolak. Periksa kembali isian Anda.");
        return;
      }
      router.push("/account");
    } finally {
      setBusy(false);
    }
  }

  return (
    <main id="konten" className="mx-auto flex max-w-md flex-col gap-4 p-6">
      <h1 className="text-2xl font-semibold text-ink">{mode === "login" ? "Masuk" : "Daftar akun"}</h1>
      <form onSubmit={onSubmit} className="flex flex-col gap-3">
        {mode === "register" && (
          <label className="flex flex-col gap-1 text-sm text-ink">
            Nama
            <input name="name" required minLength={2} maxLength={120} className="rounded border px-3 py-2" />
          </label>
        )}
        <label className="flex flex-col gap-1 text-sm text-ink">
          Email
          <input name="email" type="email" required maxLength={254} autoComplete="email" className="rounded border px-3 py-2" />
        </label>
        <label className="flex flex-col gap-1 text-sm text-ink">
          Kata sandi
          <input
            name="password"
            type="password"
            required
            minLength={mode === "register" ? 8 : 1}
            maxLength={128}
            autoComplete={mode === "login" ? "current-password" : "new-password"}
            className="rounded border px-3 py-2"
          />
        </label>
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        <button type="submit" disabled={busy} className="rounded bg-ink px-4 py-2 text-sm font-medium text-white disabled:opacity-60">
          {mode === "login" ? "Masuk" : "Daftar"}
        </button>
      </form>
      <div className="flex flex-col gap-2">
        <a href={socialLoginUrl("google")} className="rounded border px-4 py-2 text-center text-sm text-ink">
          Lanjutkan dengan Google
        </a>
        <a href={socialLoginUrl("x")} className="rounded border px-4 py-2 text-center text-sm text-ink">
          Lanjutkan dengan X
        </a>
      </div>
      <button type="button" onClick={() => setMode(mode === "login" ? "register" : "login")} className="text-sm text-ink-muted underline">
        {mode === "login" ? "Belum punya akun? Daftar" : "Sudah punya akun? Masuk"}
      </button>
    </main>
  );
}
