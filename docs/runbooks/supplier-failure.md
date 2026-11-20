# Runbook — Kegagalan supplier (hilang / telat / EOS / harga berubah setelah PO terbit)

**Severity awal:** SEV-2 → SEV-1 bila satu supplier memegang banyak `preorder_batches` aktif atau kegagalan sourcing menyentuh hak refund buyer yang sudah membayar (Blueprint §5.2 butir 8).
**Rujukan:** Blueprint §15 "supplier failure", §5.2 langkah 8 (refund diizinkan bila **gagal sourcing**), §5.4 (`procurement_status`), §4 (Procurement = domain supplier) · Doc 11 **B-4** (nama Procurement dikembalikan ke makna supplier: `suppliers`, `purchase_orders`, `purchase_order_items`, `supplier_invoices`; `goods_receipts`/`goods_receipt_items` milik Procurement), **§8.3** (`procurement_status = failed` **wajib** karena §5.2 butir 8 menjanjikan "completion/refund jika gagal sourcing"), §4.1 (`po_batches`→`preorder_batches`, `grns`→`goods_receipts`) · Blueprint §16 (test "completion/refund jika gagal sourcing") · runbook `stock-discrepancy.md`, `oversell.md`, `refund-reconciliation.md`

> Semua perintah ditandai **`# belum diverifikasi di lingkungan ini`** (README §2.1). Nilai `procurement_status` yang dipakai di sini **hanya** dari Doc 11 §8.3: `not_required · sourcing · requisitioned · po_issued · supplier_confirmed · in_transit · customs · received · failed`. **Pemilik akun supplier adalah klien** — kita mendiagnosis dan menyiapkan data, **tidak** menghubungi/menuntut supplier atas nama klien (lihat §4). Nama kolom FK pada query di bawah perlu dikonfirmasi ke Doc 09 saat dijalankan.

---

## 1. Deteksi

| Sinyal | Sumber | Ambang **(sementara — Q-4)** |
|---|---|---|
| **ETA batch terlewat** | `preorder_batches.eta` vs tanggal kini | `eta` sudah lewat dan `procurement_status` belum `received` ⇒ tinjau; > 14 hari ⇒ SEV-2 |
| PO tanpa konfirmasi supplier | `purchase_orders.status`, `supplier_confirmed` | `po_issued` tanpa `supplier_confirmed` > (lead-time tercatat + margin) ⇒ curiga "telat" |
| Supplier tidak merespons | komunikasi/katalog (di luar DB) | tidak ada kabar dari owner-account > batas **(sementara — Q-4)** ⇒ kandidat `failed` |
| EOS / barang distop | update katalog supplier (dimasukkan manual staf) | SKU batch jadi EOS setelah `po_issued` ⇒ gagal permanen, bukan telat |
| Harga berubah setelah PO terbit | `purchase_order_items` vs `supplier_invoices` | selisih harga > 0 pada PO berstatus `issued` ⇒ eksepsi harga, **bukan** pembatalan otomatis |
| Banyak batch pada satu supplier | query §3.1 | ≥ 2 batch aktif per supplier dengan ETA terlewat ⇒ SEV-1 (risiko terkonsentrasi) |

## 2. Dampak

Kegagalan supplier hanya boleh menyentuh **satu sumbu secara sah**: `orders.procurement_status`. Ketika sourcing dinyatakan gagal, nilai finalnya adalah **`failed`** (Doc 11 §8.3) — bukan menghapus order, bukan mengarang tahap fulfillment baru. Dampak turunan:

- **ETA yang sudah dijanjikan ke buyer.** `preorder_batches.eta` adalah janji ke pembeli; setelah supplier gagal, ETA itu jadi tidak realistis. Angka ini **tidak boleh** diedit diam-diam (lihat §3.5) — perubahan ETA adalah keputusan bisnis yang harus lewat jalur komunikasi + (bila perlu) refund.
- **Hak refund.** Blueprint §5.2 butir 8 memberi hak refund **bila gagal sourcing**. Ini bukan auto-adjust: hak itu dieksekusi oleh peran berwenang, dengan alasan tercatat, dan jejak audit (§3.4). Uang tidak bergerak sendiri.
- **Stok & oversell.** Order yang tertahan pada supplier gagal **tidak** menambah `on_hand`. Bila staf mencoba "mengakali" ETA dengan memindahkan stok `reserved` SKU lain, itu memicu selisih stok — rujuk `stock-discrepancy.md` dan `oversell.md`.

## 3. Langkah tanggap

### 3.1 Bekukan gambaran: batch/order menggantung per supplier

```sql
-- batch preorder menggantung + order di dalamnya, dikelompokkan per supplier
SELECT s.id AS supplier_id, s.name AS supplier,
       pb.id AS batch_id, pb.eta AS promised_eta,
       o.id AS order_id, o.procurement_status, o.payment_status, o.order_status
FROM preorder_batches pb
JOIN suppliers s        ON s.id = pb.supplier_id       -- [ verifikasi ] FK pb.supplier_id → konfirmasi di Doc 09
LEFT JOIN orders o      ON o.preorder_batch_id = pb.id -- [ verifikasi ] FK order→batch → konfirmasi di Doc 09
WHERE pb.eta < NOW()
  AND o.procurement_status IN ('requisitioned','po_issued','supplier_confirmed','in_transit','customs')
ORDER BY s.name, pb.eta;
```

```sql
-- ringkasan risiko terkonsentrasi: berapa order/money menggantung per supplier
SELECT s.id AS supplier_id, s.name AS supplier,
       COUNT(DISTINCT pb.id) AS batch_terdampak,
       COUNT(o.id)           AS order_terdampak
FROM suppliers s
JOIN preorder_batches pb ON pb.supplier_id = s.id
JOIN orders o            ON o.preorder_batch_id = pb.id
WHERE o.procurement_status <> 'received'
GROUP BY s.id, s.name
ORDER BY order_terdampak DESC;
```

`[ verifikasi ]` jumlah supplier terdampak, jumlah batch, dan jumlah order tercatat **sebelum** tindakan apa pun. Simpan keluaran ini ke log insiden.

### 3.2 Pisahkan "telat" vs "gagal permanen"

Batas waktu yang membedakan keduanya **belum ditetapkan** dan adalah **kebijakan klien** (**Q-4** — angka lead-time/ambang tidak boleh dikarang dev). Sampai angka itu ada, gunakan aturan konservatif:

| Bentuk | Ciri | Tindakan sah |
|---|---|---|
| **Telat** | supplier masih merespons, `supplier_confirmed` tercapai, ETA molor | pantau + update komunikasi; **jangan** tandai `failed` |
| **Gagal permanen** | EOS, supplier hilang, menolak lanjut, atau lewat batas **(Q-4)** | realokasi / PO pengganti / pembatalan + refund (§3.3–§3.4) |
| **Eksepsi harga** | harga `supplier_invoices` ≠ `purchase_order_items` | eskalasi ke finance (keputusan harga), **bukan** pembatalan otomatis |

```bash
docker compose -f docker/compose.yaml -f docker/compose.prod.yaml exec app php artisan tinker \
  --execute="echo 'baca purchase_orders + items + supplier_invoices untuk satu supplier terdampak';"   # belum diverifikasi di lingkungan ini — query inspeksi manual, bukan mutasi
```

`[ verifikasi ]` klasifikasi tiap batch tercatat eksplisit sebagai telat / gagal permanen / eksepsi harga, dengan dasar (tanggal, bukti respons supplier). Bila dasar ambigu → **tanya owner-account (klien)**, jangan memaksakan `failed`.

### 3.3 Opsi yang sah (pilih satu, tuangkan lewat Command — B2)

1. **Realokasi** ke batch/gudang lain yang punya barang — hanya bila stok **benar-benar tersedia** (`available_to_sell` cukup), bukan memakai `reserved` milik SKU/order lain. Efek stok lewat `stock_movements`, jangan ubah `stock_items` langsung.
2. **PO pengganti** ke supplier lain untuk order yang sama — procurement_status bergerak maju (bukan lompat ke `failed`).
3. **Pembatalan + refund** — jalurnya ada di `refund-reconciliation.md`; di sini hanya menetapkan bahwa penyebab = gagal sourcing dan `procurement_status = failed`.

```bash
# tidak ada perintah artisan pembatalan batch yang terdokumentasi di repo — operasi lewat UI Command peran preorder-ops/procurement-staff.
# Tanya operator: "apakah layar pembatalan/refund batch sudah ada di Sprint ini?" bila belum → blokir dan eskalasi, jangan SQL tulis.
```

`[ verifikasi ]` perpindahan status hanya lewat pintu Command (B2), menghasilkan event, dan **tidak** menyentuh kolom yang bukan pemilik fakta (mis. `fulfillment_status` tidak diisi `customs` — Doc 11 §8.3).

### 3.4 Refund karena gagal sourcing: siapa eksekusi & jejak audit

Blueprint §5.2 butir 8 **mengizinkan** refund, **tidak** mewajibkan sistem bergerak sendiri. Aturan:

- **Siapa:** peran `finance` (uang) berwenang mengeksekusi refund; `preorder-ops`/`sales-ops` mengajukan. Pemisahan **ajukan vs setujui** wajib. Nominal di atas ambang **(Q-4)** → persetujuan tertulis (§4).
- **Jejak:** setiap keputusan ditulis ke **`audit_logs`** (aktor, waktu, order/batch, alasan, hasil). Alasan wajib tercatat (mis. "supplier X EOS, gagal sourcing, refund penuh").
- **Tidak ada auto-adjust:** pembatalan **tidak** otomatis menggeser `payment_status`; perubahan `refund_pending → refunded` adalah langkah terpisah yang terekam dan direkonsiliasi (lihat `refund-reconciliation.md`).

```sql
-- konfirmasi jejak audit untuk batch yang diputuskan gagal
SELECT al.id, al.created_at, al.actor_id, al.action, al.subject_type, al.subject_id
FROM audit_logs al
WHERE al.subject_type IN ('order','preorder_batch')  -- [ verifikasi ] nama enum subject_type → konfirmasi Doc 09
  AND al.created_at > NOW() - INTERVAL '2 days'
ORDER BY al.created_at DESC;
```

`[ verifikasi ]` untuk setiap order yang jadi `failed`: ada baris `audit_logs` dengan alasan, actor-nya peran berwenang, dan `payment_status` **belum** berubah kecuali finance benar-benar mengeksekusi refund.

### 3.5 Opsi yang TIDAK sah

- **Mengubah ETA diam-diam di DB.** UPDATE `preorder_batches.eta` tanpa keputusan bisnis + komunikasi = memalsukan janji ke buyer. Tidak sah.
- **Mengirim barang yang belum di-`received`.** Menandai shipped atas barang yang GRN-nya belum masuk merusak ledger dan memicu `stock-discrepancy.md`.
- **Memakai stok `reserved` SKU lain** untuk menutupi batch gagal. Menyebabkan oversell (`oversell.md`).
- **Menandai `procurement_status = failed` padahal supplier cuma telat.** Membuka hak refund yang belum seharusnya.

## 4. Eskalasi

| Kondisi | Ke siapa | Kapan |
|---|---|---|
| Butuh menghubungi/menuntut supplier, ganti kontrak, konfirmasi EOS | **pemilik akun supplier = klien (owner-account)** — tanyakan, **jangan** bertindak atas nama klien | sebelum tindakan |
| Refund karena gagal sourcing, nominal di atas ambang **(Q-4)** | `finance` (setujui) + akunting klien; pemisahan ajukan vs setujui | segera |
| Batas "telat vs gagal permanen" belum ada angkanya | klien (**Q-4**) | jangan putuskan sendiri |
| Perubahan harga pada PO terbit | `finance` / owner kontrak supplier | 1×24 jam |
| Dugaan ETA diedit di DB / jejak audit dihapus | `superadmin` + `data-breach.md` | segera |

## 5. Setelah insiden

1. Bekukan angka: supplier & batch terdampak, jumlah order, ETA janji vs actual, berapa yang refund vs realokasi, total nominal refund.
2. **Update daftar risiko**: catat supplier terkonsentrasi sebagai risiko; bila penyebabnya **data lead-time** yang salah, buka **tiket** untuk koreksi lead-time per supplier (bahan kebijakan ambang §3.2 / **Q-4**).
3. Uji klaim Blueprint §16 untuk §5.2 butir 8: "supplier failed at PO ⇒ completion/refund path exercised" — tambahkan fault injection bila belum ada test transisi ke `failed`.
4. Pastikan setiap keputusan punya `audit_logs` + event terkait; refund yang dieksekusi diverifikasi silang di `refund-reconciliation.md` §3.
5. Rujukan silang yang tersentuh: `stock-discrepancy.md` (bila realokasi menyentuh stok), `oversell.md` (bila ada tekanan "kirim dari SKU lain").
