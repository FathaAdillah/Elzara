import { api } from "./api";

export async function getBatches() {
  try { return await api.GET("/preorder-batches", { cache: "no-store" }); }
  catch { return undefined; }
}

export async function getBatch(id: string) {
  try { return await api.GET("/preorder-batches/{id}", { params: { path: { id } }, cache: "no-store" }); }
  catch { return undefined; }
}
