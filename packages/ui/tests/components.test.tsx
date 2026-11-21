// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { createRef, useState } from "react";
import { afterEach, expect, test, vi } from "vitest";
import { Badge, Button, Card, Grid, Input, Modal, ToastViewport } from "../src";

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

test("a busy button is disabled and defaults to a non-submit action", () => {
  const submit = vi.fn();
  const click = vi.fn();
  const { rerender } = render(
    <form onSubmit={submit}><Button onClick={click} leadingIcon={<svg aria-label="Dekorasi" />}>Simpan</Button></form>,
  );
  const button = screen.getByRole("button", { name: "Simpan" });
  expect(button.getAttribute("type")).toBe("button");
  expect(button.classList.contains("min-h-touch")).toBe(true);
  fireEvent.click(button);
  expect(click).toHaveBeenCalledTimes(1);
  expect(submit).not.toHaveBeenCalled();

  rerender(<form onSubmit={submit}><Button loading onClick={click}>Simpan</Button></form>);
  expect(button.hasAttribute("disabled")).toBe(true);
  expect(button.getAttribute("aria-busy")).toBe("true");
  fireEvent.click(button);
  expect(click).toHaveBeenCalledTimes(1);
});

test("input label, hint, error and external description are associated", () => {
  const { rerender } = render(
    <>
      <p id="format">Alamat kerja</p>
      <Input label="Email" hint="Contoh: operator@elzara.com" error="Email tidak valid" aria-describedby="format" />
      <Input label="Nama" hint="Nama lengkap" />
    </>,
  );
  const email = screen.getByRole("textbox", { name: "Email" });
  const name = screen.getByRole("textbox", { name: "Nama" });
  expect(email.id).not.toBe(name.id);
  expect(email.getAttribute("aria-invalid")).toBe("true");
  expect(email.classList.contains("min-h-touch")).toBe(true);
  const descriptions = email.getAttribute("aria-describedby")!.split(" ").map((id) => document.getElementById(id)?.textContent);
  expect(descriptions).toEqual(["Alamat kerja", "Contoh: operator@elzara.com", "Email tidak valid"]);
  rerender(<><p id="format">Alamat kerja</p><Input label="Email" aria-describedby="format" /></>);
  expect(email.hasAttribute("aria-invalid")).toBe(false);
  expect(email.getAttribute("aria-describedby")).toBe("format");
});

test("Card, Badge and Grid preserve content and use semantic styles", () => {
  render(
    <Grid data-testid="grid" columns={{ sm: 3, md: 4, lg: 6 }}>
      <Card as="article" header={<h2>Produk</h2>} footer={<a href="/catalog">Detail</a>}>
        <Badge tone="po" icon={<svg aria-label="Dekorasi" />}>Pre-order</Badge>
      </Card>
    </Grid>,
  );
  const grid = screen.getByTestId("grid");
  for (const name of ["grid", "grid-cols-1", "sm:grid-cols-3", "md:grid-cols-4", "lg:grid-cols-6", "gap-gutter"]) {
    expect(grid.classList.contains(name)).toBe(true);
  }
  const card = within(screen.getByRole("article"));
  expect(card.getByRole("heading", { name: "Produk" })).toBeTruthy();
  expect(card.getByRole("link", { name: "Detail" }).getAttribute("href")).toBe("/catalog");
  expect(card.getByText("Pre-order").classList.contains("bg-po")).toBe(true);
  expect(card.queryByLabelText("Dekorasi")?.parentElement?.getAttribute("aria-hidden")).toBe("true");
});

test("a controlled modal is labelled, requests dismissal on Escape and restores focus", () => {
  const dialogPrototype = HTMLDialogElement.prototype;
  // jsdom has no top layer: simulate the native methods, not the browser's focus trap.
  Object.defineProperties(dialogPrototype, {
    showModal: { configurable: true, value(this: HTMLDialogElement) { this.open = true; } },
    close: {
      configurable: true,
      value(this: HTMLDialogElement) { this.open = false; if (this.contains(document.activeElement)) (document.activeElement as HTMLElement).blur(); },
    },
  });
  const initialFocusRef = createRef<HTMLButtonElement>();
  function Fixture() {
    const [open, setOpen] = useState(false);
    return (
      <>
        <button onClick={() => setOpen(true)}>Buka</button>
        <Modal title="Detail" description="Informasi produk" open={open} onClose={() => setOpen(false)} initialFocusRef={initialFocusRef}>
          <button ref={initialFocusRef}>Lanjut</button>
        </Modal>
      </>
    );
  }

  try {
    render(<Fixture />);
    const trigger = screen.getByRole("button", { name: "Buka" });
    expect(screen.queryByRole("dialog")).toBeNull();
    trigger.focus();
    fireEvent.click(trigger);
    const dialog = screen.getByRole("dialog", { name: "Detail", description: "Informasi produk" });
    expect(document.activeElement).toBe(initialFocusRef.current);
    fireEvent(dialog, new Event("cancel", { cancelable: true }));
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(document.activeElement).toBe(trigger);
  } finally {
    Reflect.deleteProperty(dialogPrototype, "showModal");
    Reflect.deleteProperty(dialogPrototype, "close");
  }
});

test("toast announces additions politely and supports keyboard dismissal", () => {
  const onDismiss = vi.fn();
  const { rerender } = render(<ToastViewport toasts={[]} />);
  const liveRegion = screen.getByRole("status");
  expect(liveRegion.textContent).toBe("");
  rerender(<ToastViewport toasts={[{ id: "saved", tone: "success", title: "Tersimpan", body: "Data telah disimpan", onDismiss }]} />);
  expect(screen.getByRole("status")).toBe(liveRegion);
  expect(liveRegion.getAttribute("aria-live")).toBe("polite");
  expect(liveRegion.textContent).toBe("Tersimpan. Data telah disimpan");
  const dismiss = screen.getByRole("button", { name: "Tutup notifikasi: Tersimpan" });
  dismiss.focus();
  expect(document.activeElement).toBe(dismiss);
  fireEvent.click(dismiss);
  expect(onDismiss).toHaveBeenCalledWith("saved");
});

test("a timed toast does not disappear while the dismiss control has focus", () => {
  vi.useFakeTimers();
  const onDismiss = vi.fn();
  render(<><button>Lainnya</button><ToastViewport toasts={[{ id: "notice", tone: "info", title: "Informasi", durationMs: 5000, onDismiss }]} /></>);
  act(() => screen.getByRole("button", { name: "Tutup notifikasi: Informasi" }).focus());
  act(() => vi.advanceTimersByTime(6000));
  expect(onDismiss).not.toHaveBeenCalled();
  act(() => screen.getByRole("button", { name: "Lainnya" }).focus());
  act(() => vi.advanceTimersByTime(5000));
  expect(onDismiss).toHaveBeenCalledOnce();
  expect(onDismiss).toHaveBeenCalledWith("notice");
});
