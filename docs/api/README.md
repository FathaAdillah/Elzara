# docs/api/

Isi direktori ini adalah **artefak turunan** dari `packages/api-contracts/openapi.yaml`,
bukan sumber kebenaran. Yang seharusnya tinggal di sini:

- `openapi-v1.html` — hasil render Redocly atas spec (Task 4 plan Sprint 0). Di-regenerate,
  tidak pernah disunting; kalau HTML dan YAML berbeda, YAML yang menang dan HTML yang salah.

Yang **tidak** boleh masuk ke sini: contoh payload yang dikarang tangan, skema paralel,
atau spesifikasi error kedua. Bentuk payload hidup di `openapi.yaml`; penjelasannya
(kenapa envelope begini, kenapa uang integer, kenapa RFC 7807) ada di
[../12-api-contract.md](../12-api-contract.md).

Cara regenerate:

```bash
docker run --rm -v "$PWD/packages/api-contracts:/spec" redocly/cli:latest build-docs /spec/openapi.yaml \
  -o /spec/dist.html && mv packages/api-contracts/dist.html docs/api/openapi-v1.html
```
